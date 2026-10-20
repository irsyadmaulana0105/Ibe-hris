<?php

return [
    // Fail-closed bridge: business CRUD remains available, file subfeatures do not.
    // Re-enable only together with a private-storage migration and authorization tests.
    'leave_attachment' => false,
    'overtime_attachment' => false,
    'client_visit_photo' => false,
    'business_trip_photo' => false,
    'shipment_pod' => false,
    'delivery_return_evidence' => false,
    'project_progress_evidence' => false,
    'recruitment_cv' => false,
    'recruitment_offer_pdf' => false,
    'contract_template_docx' => false,
];
