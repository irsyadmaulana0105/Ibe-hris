<?php

namespace App\Produksi\Http\Middleware;

use App\Produksi\Models\Vendor;
use App\Produksi\Models\VendorAccessToken;
use App\Services\ModuleEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VendorTokenMiddleware
{
    public function __construct(private readonly ModuleEntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $vendorToken = VendorAccessToken::withoutGlobalScopes()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        abort_unless($vendorToken, 404);
        $vendor = Vendor::query()->withoutGlobalScopes()
            ->where('company_id', $vendorToken->company_id)
            ->whereKey($vendorToken->vendor_id)
            ->first();
        abort_unless($vendor, 404);

        if (! $this->entitlements->enabled($vendorToken->company_id, 'production')) {
            return response()->json([
                'message' => 'Modul production tidak aktif untuk perusahaan ini.',
                'code' => 'MODULE_NOT_ENABLED',
                'module' => 'production',
            ], 403);
        }

        $vendorToken->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('vendor', $vendor);

        return $next($request);
    }
}
