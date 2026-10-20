<?php

namespace App\Support;

use LogicException;

final class EnterpriseDocumentRbacV1
{
    public const VERSION = 'enterprise-document-rbac-v1';

    public const DOCUMENT_CONTROLLER_ROLE = [
        'key' => 'document_controller',
        'label' => 'Document Controller',
        'description' => 'Controls document master data without permission to alter company bank accounts.',
        'is_builtin' => true,
    ];

    public const ADMIN_ACCESS = [
        'capability_key' => 'admin.access',
        'module' => 'admin',
        'description' => 'Enter the authenticated admin API surface; domain capabilities remain required.',
    ];

    public const ADMIN_SURFACE_ROLES = [
        'tenant_owner',
        'internal_auditor',
        'compliance_officer',
        'director',
        'document_controller',
        'sales_manager',
        'sales_officer',
        'finance_controller',
        'accountant',
        'treasury_manager',
        'treasury_officer',
    ];

    public const ADMIN_SURFACE_ROLE_LABELS = [
        'tenant_owner' => 'Tenant Owner',
        'internal_auditor' => 'Internal Auditor',
        'compliance_officer' => 'Compliance Officer',
        'director' => 'Director',
        'document_controller' => 'Document Controller',
        'sales_manager' => 'Sales Manager',
        'sales_officer' => 'Sales Officer',
        'finance_controller' => 'Finance Controller',
        'accountant' => 'Accountant',
        'treasury_manager' => 'Treasury Manager',
        'treasury_officer' => 'Treasury Officer',
    ];

    /** @var array<string, array{module: string, description: string, roles: array<int, string>}> */
    public const CAPABILITIES = [
        'documents.analytics.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for analytics view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller', 'sales_manager'],
        ],
        'documents.repository.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for repository view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller'],
        ],
        'documents.repository.manage' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for repository manage.',
            'roles' => ['tenant_owner', 'document_controller'],
        ],
        'documents.letter.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for letter view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.letter.create' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for letter create.',
            'roles' => ['tenant_owner', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.letter.manage' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for letter manage.',
            'roles' => ['tenant_owner', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.letter.approve' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for letter approve.',
            'roles' => ['tenant_owner', 'director'],
        ],
        'documents.letter.sign' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for letter sign.',
            'roles' => ['tenant_owner', 'director'],
        ],
        'documents.letter.send' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for letter send.',
            'roles' => ['tenant_owner', 'director', 'document_controller', 'sales_manager'],
        ],
        'documents.category.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for category view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.category.manage' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for category manage.',
            'roles' => ['tenant_owner', 'document_controller'],
        ],
        'documents.template.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for template view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.template.manage' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for template manage.',
            'roles' => ['tenant_owner', 'document_controller'],
        ],
        'documents.signature.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for signature view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.signature.prepare' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for signature prepare.',
            'roles' => ['tenant_owner', 'document_controller'],
        ],
        'documents.signature.approve' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for signature approve.',
            'roles' => ['tenant_owner', 'director'],
        ],
        'documents.settings.view' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for settings view.',
            'roles' => ['tenant_owner', 'internal_auditor', 'compliance_officer', 'director', 'document_controller', 'sales_manager', 'sales_officer'],
        ],
        'documents.settings.manage' => [
            'module' => 'documents',
            'description' => 'Least-privilege control for settings manage.',
            'roles' => ['tenant_owner', 'document_controller'],
        ],
        'finance.company_bank.view' => [
            'module' => 'finance',
            'description' => 'Read company bank details used on financial documents.',
            'roles' => ['tenant_owner', 'internal_auditor', 'director', 'document_controller', 'sales_manager', 'sales_officer', 'finance_controller', 'accountant', 'treasury_manager', 'treasury_officer'],
        ],
        'finance.company_bank.manage' => [
            'module' => 'finance',
            'description' => 'Create, update, deactivate, and choose the default company bank.',
            'roles' => ['tenant_owner', 'finance_controller', 'treasury_manager'],
        ],
    ];

    public const CANONICAL_SHA256 = '32de258c8cb007072889e7b5442186591ddbb746ad27b2972d4b27a8fb3b6429';

    public static function assertValid(): void
    {
        $roles = self::ADMIN_SURFACE_ROLES;
        if (count($roles) !== 11 || count(array_unique($roles)) !== 11) {
            throw new LogicException('The document-control admin role catalog is invalid.');
        }
        if (array_keys(self::ADMIN_SURFACE_ROLE_LABELS) !== $roles
            || count(array_unique(self::ADMIN_SURFACE_ROLE_LABELS)) !== 11) {
            throw new LogicException('The document-control admin role labels are invalid.');
        }

        if (count(self::CAPABILITIES) !== 20) {
            throw new LogicException('The document-control capability catalog is invalid.');
        }

        $pairs = 0;
        foreach (self::CAPABILITIES as $capability => $metadata) {
            if ($metadata['module'] !== explode('.', $capability)[0]
                || $metadata['description'] === ''
                || $metadata['roles'] === []
                || count($metadata['roles']) !== count(array_unique($metadata['roles']))) {
                throw new LogicException('The document-control capability metadata is invalid.');
            }
            foreach ($metadata['roles'] as $role) {
                if (! in_array($role, $roles, true)) {
                    throw new LogicException('The document-control capability role is invalid.');
                }
            }
            $pairs += count($metadata['roles']);
        }

        if ($pairs !== 87 || self::canonicalSha256() !== self::CANONICAL_SHA256) {
            throw new LogicException('The document-control RBAC catalog seal is invalid.');
        }
    }

    /** @return array{module: string, description: string}|null */
    public static function metadataForCapability(string $capability): ?array
    {
        if ($capability === self::ADMIN_ACCESS['capability_key']) {
            return [
                'module' => self::ADMIN_ACCESS['module'],
                'description' => self::ADMIN_ACCESS['description'],
            ];
        }

        if (! isset(self::CAPABILITIES[$capability])) {
            return null;
        }

        return [
            'module' => self::CAPABILITIES[$capability]['module'],
            'description' => self::CAPABILITIES[$capability]['description'],
        ];
    }

    public static function ownsCapabilityNamespace(string $capability): bool
    {
        return str_starts_with($capability, 'documents.')
            || str_starts_with($capability, 'finance.company_bank.');
    }

    /** @return array<int, string> */
    public static function capabilitiesForRole(string $role): array
    {
        $capabilities = [];
        foreach (self::CAPABILITIES as $capability => $metadata) {
            if (in_array($role, $metadata['roles'], true)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /** @return array<int, string> */
    public static function expectedGrantPairs(): array
    {
        $pairs = [];
        foreach (self::CAPABILITIES as $capability => $metadata) {
            foreach ($metadata['roles'] as $role) {
                $pairs[] = $role.'|'.$capability;
            }
        }
        sort($pairs, SORT_STRING);

        return $pairs;
    }

    public static function canonicalSha256(): string
    {
        return hash('sha256', json_encode([
            'version' => self::VERSION,
            'document_controller_role' => self::DOCUMENT_CONTROLLER_ROLE,
            'admin_access' => self::ADMIN_ACCESS,
            'admin_surface_roles' => self::ADMIN_SURFACE_ROLES,
            'admin_surface_role_labels' => self::ADMIN_SURFACE_ROLE_LABELS,
            'capabilities' => self::CAPABILITIES,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
