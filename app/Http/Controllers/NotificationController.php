<?php
namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
class NotificationController extends Controller {
    public function index(Request $request): View { $notifications=$request->user()->notifications()->latest()->paginate(20); return view('notifications.index',compact('notifications')); }
    public function poll(Request $request): JsonResponse { return response()->json(['unread'=>$request->user()->unreadNotifications()->count(),'notifications'=>$request->user()->notifications()->latest()->limit(8)->get()->map(fn($n)=>['id'=>$n->id,'title'=>$n->data['title']??'Notifikasi','message'=>$n->data['message']??'','url'=>$n->data['url']??route('notifications.index'),'read_at'=>$n->read_at?->toISOString(),'created_at'=>$n->created_at->toISOString()])]); }
    public function read(Request $request, string $notification): RedirectResponse { $item=$request->user()->notifications()->whereKey($notification)->firstOrFail(); $item->markAsRead(); return redirect($item->data['url'] ?? route('notifications.index')); }
    public function readAll(Request $request): RedirectResponse { $request->user()->unreadNotifications->markAsRead(); return back()->with('status','Semua notifikasi ditandai sudah dibaca.'); }
}
