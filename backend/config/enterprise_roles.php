<?php

use App\Support\EnterpriseDocumentRbacV1;

EnterpriseDocumentRbacV1::assertValid();

return [
    'roles' => [
        'tenant_owner' => ['Tenant Owner', [
            'admin.access', 'company.manage', 'rbac.manage', 'theme.manage', 'integration.manage', 'governance.audit.view',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('tenant_owner'),
        ]],
        'security_admin' => ['Security Administrator', ['rbac.manage', 'audit_logs.view', 'security.assignment.manage', 'security.access_review.manage']],
        'internal_auditor' => ['Internal Auditor', [
            'admin.access', 'audit_logs.view', 'reports.view', 'governance.audit.view', 'governance.audit.export',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('internal_auditor'),
        ]],
        'compliance_officer' => ['Compliance Officer', [
            'admin.access', 'audit_logs.view', 'reports.view', 'governance.record.manage', 'governance.evidence.manage',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('compliance_officer'),
        ]],
        'director' => ['Director', [
            'admin.access', 'reports.view', 'executive.dashboard.view', 'executive.approval.decide', 'contract.final_sign',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('director'),
        ]],
        'document_controller' => ['Document Controller', [
            'admin.access',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('document_controller'),
        ]],
        'hr_manager' => ['HR Manager', ['admin.access', 'employees.manage', 'attendance.manage', 'leave.manage', 'overtime.manage', 'performance.manage', 'recruitment.manage', 'people.lifecycle.approve']],
        'hr_officer' => ['HR Officer', ['admin.access', 'employees.manage', 'attendance.manage', 'leave.manage', 'overtime.manage', 'people.employee.create', 'people.employee.update']],
        'payroll_manager' => ['Payroll Manager', ['admin.access', 'payroll.manage', 'payroll.approve', 'payroll.run.approve']],
        'payroll_officer' => ['Payroll Officer', ['admin.access', 'payroll.submit', 'payroll.run.create', 'payroll.run.submit']],
        'marketing_manager' => ['Marketing Manager', ['admin.access', 'crm.view', 'crm.manage', 'crm.admin', 'marketing.campaign.approve']],
        'marketing_officer' => ['Marketing Officer', ['admin.access', 'crm.view', 'crm.manage', 'marketing.lead.create', 'marketing.lead.update']],
        'sales_manager' => ['Sales Manager', [
            'admin.access', 'crm.view', 'crm.manage', 'sales.quotation.approve', 'sales.order.approve',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('sales_manager'),
        ]],
        'sales_officer' => ['Sales Officer', [
            'admin.access', 'crm.view', 'crm.manage', 'sales.quotation.create', 'sales.order.create',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('sales_officer'),
        ]],
        'finance_controller' => ['Finance Controller', [
            'admin.access', 'finance.module.access', 'reports.view', 'audit_logs.view',
            'finance.journal.post', 'finance.journal.reverse',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('finance_controller'),
        ]],
        'accountant' => ['Accountant', [
            'admin.access', 'finance.module.access', 'finance.journal.create', 'finance.invoice.manage',
            'finance.bill.manage',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('accountant'),
        ]],
        'treasury_manager' => ['Treasury Manager', [
            'admin.access', 'finance.module.access', 'treasury.payment.approve',
            'treasury.reconciliation.approve',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('treasury_manager'),
        ]],
        'treasury_officer' => ['Treasury Officer', [
            'admin.access', 'finance.module.access', 'treasury.payment.execute',
            'treasury.reconciliation.prepare',
            ...EnterpriseDocumentRbacV1::capabilitiesForRole('treasury_officer'),
        ]],
        'procurement_manager' => ['Procurement Manager', ['admin.access', 'inventory.view', 'inventory.manage', 'inventory.admin', 'procurement.po.approve', 'procurement.vendor.approve']],
        'procurement_officer' => ['Procurement Officer', ['admin.access', 'inventory.view', 'inventory.manage', 'procurement.rfq.manage', 'procurement.po.create']],
        'warehouse_manager' => ['Warehouse Manager', ['admin.access', 'inventory.view', 'inventory.manage', 'inventory.admin', 'warehouse.adjustment.approve']],
        'warehouse_operator' => ['Warehouse Operator', ['admin.access', 'inventory.view', 'inventory.manage', 'warehouse.receipt.post', 'warehouse.pick_pack.execute']],
        'production_manager' => ['Production Manager', ['admin.access', 'production.view', 'production.manage', 'production.admin', 'inventory.view', 'production.work_order.release']],
        'production_operator' => ['Production Operator', ['admin.access', 'production.view', 'production.manage', 'inventory.view', 'production.operation.report']],
        'qc_inspector' => ['QC Inspector', ['admin.access', 'production.view', 'production.quality.inspect', 'production.quality.disposition']],
        'distribution_manager' => ['Distribution Manager', ['admin.access', 'inventory.view', 'inventory.manage', 'distribution.shipment.approve', 'distribution.exception.manage']],
        'dispatcher' => ['Dispatcher', ['admin.access', 'inventory.view', 'inventory.manage', 'distribution.shipment.dispatch', 'distribution.pod.review']],
        'project_director' => ['Project Director', ['admin.access', 'project.view', 'project.manage', 'project.rab.approve', 'project.baseline.approve']],
        'project_manager' => ['Project Manager', ['admin.access', 'project.view', 'project.manage', 'project.rab.create', 'project.progress.manage']],
        'service_manager' => ['Customer Service Manager', ['admin.access', 'complaint.manage', 'service.ticket.assign', 'service.ticket.close']],
        'service_agent' => ['Customer Service Agent', ['admin.access', 'complaint.manage', 'service.ticket.respond']],
        'employee' => ['Employee', ['self.service', 'attendance.self', 'people.leave.submit', 'expense.reimbursement.submit']],
        'mcp_auditor' => ['MCP Read Auditor', ['reports.view', 'ai.mcp.read', 'governance.audit.view']],
    ],
];
