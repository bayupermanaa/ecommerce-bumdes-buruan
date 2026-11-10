<?php
namespace Tests\Feature;
use App\Models\UmkmRecipientBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class UmkmRecipientBankAccountTest extends TestCase
{
 use RefreshDatabase;
 public function test_owner_manages_own_account_and_activation_is_singleton(): void {
  $owner=User::factory()->create(['role'=>'penjual']); $store=$owner->umkmStore()->create(['name'=>'Toko A','slug'=>'toko-a']);
  $this->actingAs($owner)->post(route('umkm.recipient-bank-accounts.store'),['bank_name'=>'Bank A','account_holder_name'=>'Nama A','account_number'=>'0012345'])->assertRedirect();
  $first=$store->recipientBankAccounts()->sole(); $this->assertSame('0012345',$first->account_number);
  $this->actingAs($owner)->post(route('umkm.recipient-bank-accounts.store'),['bank_name'=>'Bank B','account_holder_name'=>'Nama B','account_number'=>'0098765'])->assertRedirect();
  $this->assertSame('inactive',$first->fresh()->status); $this->assertSame(1,$store->recipientBankAccounts()->where('status','active')->count());
  $admin=User::factory()->create(['role'=>'admin_bumdes']); $second=$store->recipientBankAccounts()->where('status','active')->first();
  $this->actingAs($admin)->patch(route('admin.recipient-bank-accounts.verify',$second))->assertRedirect(); $this->assertNotNull($second->fresh()->verified_at);
  $this->actingAs($owner)->patch(route('umkm.recipient-bank-accounts.update',$second),['bank_name'=>'Bank B','account_holder_name'=>'Nama Baru','account_number'=>'0098765'])->assertRedirect(); $this->assertNull($second->fresh()->verified_at);
 }
 public function test_other_roles_and_owner_cannot_access_other_store_account(): void {
  $a=User::factory()->create(['role'=>'penjual']);$s=$a->umkmStore()->create(['name'=>'A','slug'=>'a-'.Str::random(6)]);$account=$s->recipientBankAccounts()->create(['bank_name'=>'B','account_holder_name'=>'N','account_number'=>'00123','status'=>'active','active_store_lock'=>'store:'.$s->id]);
  $b=User::factory()->create(['role'=>'penjual']);$b->umkmStore()->create(['name'=>'B','slug'=>'b-'.Str::random(6)]);$customer=User::factory()->create(['role'=>'pelanggan']);
  $this->actingAs($b)->patch(route('umkm.recipient-bank-accounts.deactivate',$account))->assertForbidden();$this->actingAs($customer)->get(route('umkm.recipient-bank-accounts.index'))->assertForbidden();$this->actingAs($customer)->patch(route('admin.recipient-bank-accounts.verify',$account))->assertForbidden();
 }
}
