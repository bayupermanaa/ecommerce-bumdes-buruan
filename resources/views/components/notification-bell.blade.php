<div x-data="notificationBell('{{ route('notifications.poll') }}')" x-init="start()" class="relative">
    <a href="{{ route('notifications.index') }}" class="relative inline-flex rounded-xl border border-slate-200 p-2.5 text-slate-600 hover:bg-slate-50" aria-label="Notifikasi"><span class="text-lg">&#128276;</span><span x-show="unread > 0" x-text="unread > 99 ? '99+' : unread" class="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-600 px-1 text-center text-[10px] font-bold text-white"></span></a>
</div>
<script>function notificationBell(url){return{unread:0,start(){this.refresh();setInterval(()=>this.refresh(),15000)},refresh(){fetch(url,{headers:{Accept:'application/json'}}).then(r=>r.ok?r.json():null).then(d=>{if(d)this.unread=d.unread}).catch(()=>{})}}}</script>
