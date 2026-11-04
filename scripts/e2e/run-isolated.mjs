import { randomBytes } from 'node:crypto';
import { promises as fs } from 'node:fs';
import { createServer } from 'node:net';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const temporaryBase = path.join(projectRoot, '.e2e-tmp');
await fs.mkdir(temporaryBase, { recursive: true });
const temporaryRoot = await fs.mkdtemp(path.join(temporaryBase, 'run-'));
const isolatedAppRoot = path.join(temporaryRoot, 'app');
const databaseDirectory = path.join(temporaryRoot, 'database');
const databaseFile = path.join(databaseDirectory, 'e2e.sqlite');
const port = await freePort();
const baseUrl = `http://127.0.0.1:${port}`;
let server;

function within(child, parent) {
    const relative = path.relative(parent, child);

    return relative !== '' && !relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative);
}

function assertIsolatedEnvironment(environment) {
    const resolvedDatabase = path.resolve(environment.DB_DATABASE);

    if (environment.DB_CONNECTION !== 'sqlite') {
        throw new Error('E2E stopped: DB_CONNECTION must be sqlite.');
    }

    if (environment.DB_DATABASE === ':memory:' || !within(resolvedDatabase, temporaryRoot)) {
        throw new Error('E2E stopped: DB_DATABASE must be a SQLite file inside the E2E temporary directory.');
    }

    if (/ecommerce_bumdes_restore/i.test(resolvedDatabase)) {
        throw new Error('E2E stopped: development database name is forbidden.');
    }

    if (environment.QUEUE_CONNECTION !== 'sync' || environment.MAIL_MAILER !== 'array') {
        throw new Error('E2E stopped: queue must be sync and mailer must be array.');
    }
}

function e2eEnvironment() {
    const environment = {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_URL: baseUrl,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: databaseFile,
        DB_URL: '',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'file',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array',
        FILESYSTEM_DISK: 'local',
        LOG_CHANNEL: 'single',
        LOG_LEVEL: 'error',
        E2E_ISOLATED: '1',
    };

    assertIsolatedEnvironment(environment);

    return environment;
}

async function copyIsolatedApplication() {
    const excludedTopLevel = new Set([
        '.env',
        '.git',
        'node_modules',
        'storage',
        'test-results',
    ]);

    await fs.mkdir(isolatedAppRoot, { recursive: true });

    for (const entry of await fs.readdir(projectRoot)) {
        if (excludedTopLevel.has(entry) || entry === '.e2e-tmp') {
            continue;
        }

        if (entry === 'public') {
            const isolatedPublic = path.join(isolatedAppRoot, 'public');
            await fs.mkdir(isolatedPublic, { recursive: true });

            for (const publicEntry of await fs.readdir(path.join(projectRoot, 'public'))) {
                if (publicEntry !== 'storage') {
                    await fs.cp(
                        path.join(projectRoot, 'public', publicEntry),
                        path.join(isolatedPublic, publicEntry),
                        { recursive: true },
                    );
                }
            }

            continue;
        }

        await fs.cp(path.join(projectRoot, entry), path.join(isolatedAppRoot, entry), {
            recursive: true,
        });
    }

    await fs.mkdir(databaseDirectory, { recursive: true });
    await fs.writeFile(databaseFile, '');

    await Promise.all([
        fs.mkdir(path.join(isolatedAppRoot, 'storage', 'app', 'private'), { recursive: true }),
        fs.mkdir(path.join(isolatedAppRoot, 'storage', 'app', 'public'), { recursive: true }),
        fs.mkdir(path.join(isolatedAppRoot, 'storage', 'framework', 'cache', 'data'), { recursive: true }),
        fs.mkdir(path.join(isolatedAppRoot, 'storage', 'framework', 'sessions'), { recursive: true }),
        fs.mkdir(path.join(isolatedAppRoot, 'storage', 'framework', 'views'), { recursive: true }),
        fs.mkdir(path.join(isolatedAppRoot, 'storage', 'logs'), { recursive: true }),
        fs.mkdir(path.join(isolatedAppRoot, 'bootstrap', 'cache'), { recursive: true }),
    ]);

    await fs.writeFile(path.join(isolatedAppRoot, 'e2e-router.php'), `<?php
$publicPath = __DIR__.DIRECTORY_SEPARATOR.'public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require $publicPath.DIRECTORY_SEPARATOR.'index.php';
`);
}

function run(executable, args, options) {
    return new Promise((resolve, reject) => {
        const child = spawn(executable, args, { stdio: 'inherit', ...options });

        child.once('error', reject);
        child.once('exit', (code) => {
            if (code === 0) {
                resolve();
            } else {
                reject(new Error(`${executable} ${args.join(' ')} exited with code ${code}.`));
            }
        });
    });
}

function startServer(environment) {
    server = spawn('php', [
        '-S',
        `127.0.0.1:${port}`,
        '-t',
        'public',
        'e2e-router.php',
    ], {
        cwd: isolatedAppRoot,
        env: environment,
        stdio: 'inherit',
    });

    return new Promise((resolve, reject) => {
        server.once('error', reject);
        setTimeout(resolve, 500);
    });
}

async function waitForHealth() {
    const deadline = Date.now() + 20_000;

    while (Date.now() < deadline) {
        try {
            const response = await fetch(`${baseUrl}/up`);
            if (response.ok) {
                return;
            }
        } catch {
            // The server can still be booting.
        }

        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    throw new Error(`E2E stopped: isolated Laravel server did not become healthy at ${baseUrl}.`);
}

async function freePort() {
    return new Promise((resolve, reject) => {
        const probe = createServer();
        probe.unref();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const address = probe.address();
            probe.close(() => resolve(address.port));
        });
    });
}

async function cleanup() {
    if (server && server.exitCode === null && !server.killed) {
        const serverPid = server.pid;

        server.kill();
        const gracefulDeadline = Date.now() + 2_000;
        while (serverPid && isProcessRunning(serverPid) && Date.now() < gracefulDeadline) {
            await new Promise((resolve) => setTimeout(resolve, 100));
        }

        if (process.platform === 'win32' && serverPid && isProcessRunning(serverPid)) {
            await new Promise((resolve) => {
                const taskkill = spawn('taskkill', ['/pid', String(serverPid), '/t', '/f'], {
                    stdio: 'ignore',
                    windowsHide: true,
                });
                const timeout = setTimeout(resolve, 5_000);
                const finish = () => {
                    clearTimeout(timeout);
                    resolve();
                };

                taskkill.once('error', finish);
                taskkill.once('close', finish);
            });
        }

        const deadline = Date.now() + 15_000;
        while (serverPid && isProcessRunning(serverPid) && Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, 100));
        }

        if (serverPid && isProcessRunning(serverPid)) {
            throw new Error(`E2E stopped: isolated Laravel server process ${serverPid} did not terminate.`);
        }

        server.unref();
        server.removeAllListeners();
    }

    if (process.env.E2E_KEEP_ARTIFACTS !== '1') {
        await fs.rm(temporaryRoot, {
            recursive: true,
            force: true,
            maxRetries: 20,
            retryDelay: 500,
        });
    } else {
        console.log(`E2E temporary environment retained at: ${temporaryRoot}`);
    }
}

function isProcessRunning(pid) {
    try {
        process.kill(pid, 0);

        return true;
    } catch (error) {
        if (error.code === 'ESRCH') {
            return false;
        }

        return true;
    }
}

try {
    const environment = e2eEnvironment();
    console.log(`E2E isolation verified: ${databaseFile}`);
    await copyIsolatedApplication();

    // This migration is executed only after the fail-closed SQLite checks above,
    // against the newly-created file in the temporary E2E directory.
    console.log('Running migrations against the isolated SQLite file...');
    await run('php', ['artisan', 'migrate', '--force', '--no-interaction'], {
        cwd: isolatedAppRoot,
        env: environment,
    });

    console.log('Seeding synthetic authenticated E2E fixtures into the isolated SQLite file...');
    await run('php', ['scripts/e2e/seed-authenticated-fixtures.php'], {
        cwd: isolatedAppRoot,
        env: environment,
    });

    console.log(`Starting isolated Laravel server at ${baseUrl}...`);
    await startServer(environment);
    await waitForHealth();
    console.log('Running Playwright smoke test...');
    const playwrightArgs = [path.join(projectRoot, 'node_modules', '@playwright', 'test', 'cli.js'), 'test'];
    if (process.env.E2E_GREP) {
        playwrightArgs.push('--grep', process.env.E2E_GREP);
    }
    if (process.env.E2E_PROJECT) {
        playwrightArgs.push('--project', process.env.E2E_PROJECT);
    }
    await run(process.execPath, playwrightArgs, {
        cwd: projectRoot,
        env: { ...environment, E2E_BASE_URL: baseUrl },
    });
} finally {
    await cleanup();
}
