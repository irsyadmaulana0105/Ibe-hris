<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductSerialNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF Nameplate / Label produk - output gaya Toyota Forklift label:
 * Serial number besar di tengah, list spec fields yang show_on_nameplate=true
 * dalam grid 2-kolom. Cetak di sticker / plate metal.
 */
class NameplatePdfController extends ApiController
{
    public function show(Request $request, string $id): Response
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $sn = ProductSerialNumber::query()
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        $product = $sn->product_id
            ? Product::query()->where('company_id', $user->company_id)->with('specFields')->find($sn->product_id)
            : null;

        // Compose nameplate fields: hanya spec yang show_on_nameplate=true
        $fields = [];
        if ($product) {
            foreach ($product->specFields as $f) {
                if (!$f->show_on_nameplate) continue;
                $value = $sn->spec_values[$f->field_key] ?? '';
                if ($value === '' || $value === null) continue;
                $fields[] = [
                    'label' => $f->label,
                    'value' => $value,
                    'unit'  => $f->unit,
                ];
            }
        }

        $pdf = Pdf::loadView('pdf.nameplate', [
            'sn'      => $sn,
            'product' => $product,
            'fields'  => $fields,
            'company' => $user->company,
        ])->setPaper('a5', 'landscape');

        $filename = "nameplate-{$sn->serial_number}.pdf";
        return $pdf->stream($filename, ['Attachment' => false]);
    }
}
