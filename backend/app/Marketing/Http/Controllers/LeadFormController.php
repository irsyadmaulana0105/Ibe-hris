<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\LeadForm;
use App\Marketing\Services\LeadFormService;
use App\Marketing\Support\PenggunaAktif;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD untuk Web Forms - capability crm.manage minimum.
 */
class LeadFormController extends ApiController
{
    public function __construct(
        private readonly LeadFormService $forms,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $rows = LeadForm::query()
            ->where('company_id', $user->company_id)
            ->orderByDesc('updated_at')
            ->paginate(30);

        return $this->paginated($rows, $rows->items());
    }

    /**
     * GET /crm/forms/owners - pilihan penanggung jawab lead formulir web.
     * Hanya pengguna aktif yang bisa mengelola CRM; lead yang diberikan ke
     * orang tanpa hak itu tidak akan pernah ditindaklanjuti.
     */
    public function owners(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $rows = User::query()
            ->where('company_id', $user->company_id)
            ->where(fn ($q) => PenggunaAktif::saring($q))
            ->orderBy('name')
            ->get(['id', 'name', 'group'])
            ->filter(fn (User $u) => $u->legacyHasCapability('crm.manage'))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return $this->success($rows);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $form = LeadForm::query()
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        return $this->success($form);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $this->validateData($request);
        $data['company_id'] = $user->company_id;
        $data['token'] = $this->forms->generateToken();
        $data['created_by'] = $user->id;

        $form = LeadForm::create($data);
        $this->audit->log('crm.form.created', $user, 'crm_lead_form', (string) $form->id, ['name' => $form->name]);

        return $this->created($form, "Form '{$form->name}' dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $form = LeadForm::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $this->validateData($request, true);
        $form->update($data);

        return $this->success($form->fresh());
    }

    /** POST /crm/forms/{id}/rotate-token - invalidate URL lama, generate token baru */
    public function rotateToken(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.admin');

        $form = LeadForm::query()->where('company_id', $user->company_id)->findOrFail($id);
        $form->update(['token' => $this->forms->generateToken()]);

        return $this->success(['token' => $form->token], 'Token diperbarui. URL form lama tidak bisa dipakai lagi.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.admin');

        $form = LeadForm::query()->where('company_id', $user->company_id)->findOrFail($id);
        $form->delete();

        return $this->success(message: 'Form dihapus.');
    }

    /** GET /crm/forms/{id}/submissions - recent submissions */
    public function submissions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $form = LeadForm::query()->where('company_id', $user->company_id)->findOrFail($id);
        $rows = $form->submissions()
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    private function validateData(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$rule, 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'fields' => [$rule, 'array', 'min:1'],
            'fields.*.key' => ['required', 'string', 'max:50'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', 'in:text,email,tel,textarea,select,number,url'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['sometimes', 'array'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:150'],
            'success_message' => ['nullable', 'string', 'max:500'],
            'redirect_url' => ['nullable', 'url', 'max:500'],
            'notify_emails' => ['nullable', 'array'],
            'notify_emails.*' => ['email'],
            'default_source' => ['nullable', 'string', 'max:50'],
            // Dibatasi ke tenant sendiri; dulu ULID mana pun diterima.
            'default_owner_id' => ['nullable', 'string', 'size:26',
                Rule::exists('users', 'id')->where('company_id', $request->user()->company_id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
