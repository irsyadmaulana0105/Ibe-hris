<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Contract;
use App\Services\PrivateAssetStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContractAssetController extends ApiController
{
    private const ASSETS = [
        'employee-signature' => ['path' => 'karyawan_signature_path', 'checksum' => 'karyawan_signature_sha256', 'kind' => 'png'],
        'hrd-paraf-signature' => ['path' => 'hrd_paraf_signature_path', 'checksum' => 'hrd_paraf_signature_sha256', 'kind' => 'png'],
        'director-signature' => ['path' => 'direktur_signature_path', 'checksum' => 'direktur_signature_sha256', 'kind' => 'png'],
        'signed-pdf' => ['path' => 'signed_pdf_path', 'checksum' => 'signed_pdf_sha256', 'kind' => 'pdf'],
        'materai-pdf' => ['path' => 'materai_pdf_path', 'checksum' => 'materai_pdf_sha256', 'kind' => 'pdf'],
    ];

    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    public function show(Request $request, int $id, string $asset): Response
    {
        $user = $request->user();
        $definition = self::ASSETS[$asset] ?? null;
        abort_if($definition === null, 404);

        $contract = Contract::query()->withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        if ((string) $contract->user_id !== (string) $user->id) {
            $this->requireAnyCapability(
                $user,
                ['employees.manage', 'contract.final_sign'],
                'Contract asset access required.',
            );
        }

        $path = $contract->{$definition['path']};
        if ($asset === 'director-signature'
            && (! is_string($path) || $path === '')
            && $contract->direktur_signature_unavailable_at !== null) {
            throw new PrivateAssetIntegrityException(
                'unavailable',
                'The archived director signature is unavailable and retained as an audited tombstone.',
                410,
            );
        }
        abort_if(! is_string($path) || $path === '', 404);

        $extension = $definition['kind'] === 'png' ? 'png' : 'pdf';

        return $this->privateAssets->downloadResponse(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            $path,
            $contract->{$definition['checksum']},
            $definition['kind'],
            'contract-'.$contract->id.'-'.$asset.'.'.$extension,
        );
    }
}
