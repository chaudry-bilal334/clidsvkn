<?php

use App\Http\Controllers\Api\AiContentDraftController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CdssController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\DiagnosisController;
use App\Http\Controllers\Api\DispensingController;
use App\Http\Controllers\Api\DoctorAvailabilityController;
use App\Http\Controllers\Api\EncounterController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FollowupController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JazzCashPaymentController;
use App\Http\Controllers\Api\LabOrderController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MedicalDocumentController;
use App\Http\Controllers\Api\MedRecController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationTemplateController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientMedicalHistoryController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\PublicSiteController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\ReauthController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SecurityEventController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SystemSettingsController;
use App\Http\Controllers\Api\TelehealthController;
use App\Http\Controllers\Api\TwoFactorAuthController;
use App\Http\Controllers\Api\VitalsController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('v1')->group(function () {
    Route::get('csrf-cookie', [AuthController::class, 'csrfCookie'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('auth/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:auth');

    // JazzCash posts the hosted-checkout result here (public, no session auth,
    // CSRF-exempt in bootstrap/app.php). Signature verification happens inside
    // the controller before any state changes.
    Route::post('jazzcash/callback', [JazzCashPaymentController::class, 'callback']);

    Route::middleware(['auth', 'throttle:sensitive'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::middleware(['auth', 'throttle:sensitive'])->prefix('auth/2fa')->group(function () {
        Route::post('enable', [TwoFactorAuthController::class, 'enable']);
        Route::post('confirm', [TwoFactorAuthController::class, 'confirm']);
        Route::post('disable', [TwoFactorAuthController::class, 'disable']);
    });

    Route::middleware(['auth', 'throttle:sensitive'])->prefix('reauth')->group(function () {
        Route::get('status', [ReauthController::class, 'status']);
        Route::post('', [ReauthController::class, 'confirm']);
    });

    // Public clinic website (no authentication; marketing + booking requests)
    Route::prefix('public')->middleware('throttle:public')->group(function () {
        Route::get('clinic/info', [PublicSiteController::class, 'info']);
        Route::get('services', [PublicSiteController::class, 'services']);
        Route::get('doctors', [PublicSiteController::class, 'doctors']);
        Route::post('booking', [PublicSiteController::class, 'booking'])->middleware('throttle:public-submit');
        Route::post('contact', [PublicSiteController::class, 'contact'])->middleware('throttle:public-submit');
    });

    // Health check - public endpoint (no auth required)
    Route::get('health', [HealthCheckController::class, 'check']);

    // Prometheus metrics endpoint
    Route::get('metrics', [MetricsController::class, 'index']);

    Route::middleware(['auth', '2fa'])->group(function () {
        Route::get('branches', [BranchController::class, 'index']);
        Route::get('branches/{branch}/staff', [BranchController::class, 'staff']);
        Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.manage');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.manage');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:branches.manage');
        Route::get('patients', [PatientController::class, 'index'])->middleware('permission:patients.view');
        Route::post('patients', [PatientController::class, 'store'])->middleware('permission:patients.manage');
        Route::get('patients/{patient}', [PatientController::class, 'show'])->middleware('permission:patients.view');
        Route::get('patients/{patient}/safety-banner', [PatientController::class, 'safetyBanner'])->middleware('permission:patients.view');
        Route::put('patients/{patient}', [PatientController::class, 'update'])->middleware('permission:patients.manage');
        Route::delete('patients/{patient}', [PatientController::class, 'destroy'])->middleware('permission:patients.manage');
        Route::post('patients/merge', [PatientController::class, 'merge'])
            ->middleware(['permission:patients.manage', 'throttle:sensitive']);

        Route::get('appointments', [AppointmentController::class, 'index'])
            ->middleware('permission:appointments.view');
        Route::post('appointments', [AppointmentController::class, 'store'])->middleware('permission:appointments.manage');
        Route::put('appointments/{appointment}', [AppointmentController::class, 'update'])->middleware('permission:appointments.manage');
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'status'])
            ->middleware(['permission:appointments.manage', 'throttle:sensitive']);

        Route::get('doctors', [DoctorAvailabilityController::class, 'doctors'])->middleware('permission:appointments.view');
        Route::get('doctors/{doctor}/availability', [DoctorAvailabilityController::class, 'availability'])->middleware('permission:appointments.view');

        Route::get('doctors/{doctor}/schedules', [DoctorAvailabilityController::class, 'schedules'])->middleware('permission:appointments.view');
        Route::post('doctors/{doctor}/schedules', [DoctorAvailabilityController::class, 'storeSchedule'])->middleware('permission:appointments.manage');
        Route::put('schedules/{schedule}', [DoctorAvailabilityController::class, 'updateSchedule'])->middleware('permission:appointments.manage');
        Route::delete('schedules/{schedule}', [DoctorAvailabilityController::class, 'destroySchedule'])->middleware('permission:appointments.manage');

        Route::get('doctors/{doctor}/time-offs', [DoctorAvailabilityController::class, 'timeOffs'])->middleware('permission:appointments.view');
        Route::post('doctors/{doctor}/time-offs', [DoctorAvailabilityController::class, 'storeTimeOff'])->middleware('permission:appointments.manage');
        Route::put('time-offs/{timeOff}', [DoctorAvailabilityController::class, 'updateTimeOff'])->middleware('permission:appointments.manage');
        Route::delete('time-offs/{timeOff}', [DoctorAvailabilityController::class, 'destroyTimeOff'])->middleware('permission:appointments.manage');

        Route::get('queue', [QueueController::class, 'index'])->middleware('permission:appointments.view');
        Route::post('queue', [QueueController::class, 'store'])->middleware('permission:appointments.manage');
        Route::put('queue/{queueEntry}', [QueueController::class, 'update'])->middleware('permission:appointments.manage');

        Route::get('encounters', [EncounterController::class, 'index'])->middleware('permission:encounters.view');
        Route::post('encounters', [EncounterController::class, 'store'])->middleware('permission:encounters.manage');
        Route::get('encounters/{encounter}', [EncounterController::class, 'show'])->middleware('permission:encounters.view');
        Route::post('encounters/{encounter}/finalize', [EncounterController::class, 'finalize'])
            ->middleware(['permission:encounters.manage', 'throttle:sensitive', 'reauth']);
        Route::post('encounters/{encounter}/void', [EncounterController::class, 'void'])
            ->middleware(['permission:encounters.manage', 'throttle:sensitive', 'reauth']);
        Route::post('encounters/{encounter}/amend', [EncounterController::class, 'amend'])
            ->middleware(['permission:encounters.manage', 'throttle:sensitive', 'reauth']);

        Route::get('patients/{patient}/medical-history', [PatientMedicalHistoryController::class, 'index'])->middleware('permission:encounters.view');
        Route::post('patients/{patient}/medical-history', [PatientMedicalHistoryController::class, 'store'])->middleware('permission:encounters.manage');
        Route::put('medical-history/{medicalHistory}', [PatientMedicalHistoryController::class, 'update'])->middleware('permission:encounters.manage');

        // Encounter vitals
        Route::get('encounters/{encounter}/vitals', [VitalsController::class, 'index'])->middleware('permission:encounters.view');
        Route::post('encounters/{encounter}/vitals', [VitalsController::class, 'store'])->middleware('permission:encounters.manage');
        Route::put('vitals/{vital}', [VitalsController::class, 'update'])->middleware('permission:encounters.manage');
        Route::delete('vitals/{vital}', [VitalsController::class, 'destroy'])->middleware('permission:encounters.manage');

        // Encounter diagnoses
        Route::get('encounters/{encounter}/diagnoses', [DiagnosisController::class, 'index'])->middleware('permission:encounters.view');
        Route::post('encounters/{encounter}/diagnoses', [DiagnosisController::class, 'store'])->middleware('permission:encounters.manage');
        Route::put('diagnoses/{diagnosis}', [DiagnosisController::class, 'update'])->middleware('permission:encounters.manage');
        Route::delete('diagnoses/{diagnosis}', [DiagnosisController::class, 'destroy'])->middleware('permission:encounters.manage');

        // Medication Reconciliation
        Route::get('patients/{patient}/medication-reconciliations', [MedRecController::class, 'index'])->middleware('permission:encounters.view');
        Route::post('patients/{patient}/medication-reconciliations', [MedRecController::class, 'store'])->middleware('permission:encounters.manage');
        Route::put('medication-reconciliations/{medicationReconciliation}', [MedRecController::class, 'update'])->middleware('permission:encounters.manage');
        Route::post('medication-reconciliations/{medicationReconciliation}/deactivate', [MedRecController::class, 'deactivate'])->middleware('permission:encounters.manage');
        Route::post('encounters/{encounter}/medication-reconciliations/reconcile', [MedRecController::class, 'reconcile'])->middleware('permission:encounters.manage');

        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:billing.view');
        Route::post('invoices', [InvoiceController::class, 'store'])->middleware('permission:billing.manage');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->middleware('permission:billing.manage');
        Route::post('invoices/{invoice}/payments/{payment}/reverse', [InvoiceController::class, 'reversePayment'])->middleware(['permission:billing.manage', 'reauth']);
        Route::post('invoices/{invoice}/jazzcash/initiate', [JazzCashPaymentController::class, 'initiate'])->middleware('permission:billing.manage');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('permission:billing.view');
        Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])->middleware(['permission:billing.manage', 'reauth']);
        Route::post('invoices/{invoice}/refunds', [InvoiceController::class, 'refund'])->middleware(['permission:billing.manage', 'reauth']);

        Route::get('prescriptions', [PrescriptionController::class, 'index'])->middleware('permission:prescriptions.view');
        Route::post('prescriptions', [PrescriptionController::class, 'store'])->middleware('permission:prescriptions.manage');
        Route::get('prescriptions/{prescription}/pdf', [PrescriptionController::class, 'pdf'])->middleware('permission:prescriptions.view');
        Route::post('prescriptions/{prescription}/finalize', [PrescriptionController::class, 'finalize'])
            ->middleware(['permission:prescriptions.manage', 'throttle:sensitive', 'reauth']);
        Route::post('prescriptions/{prescription}/void', [PrescriptionController::class, 'void'])
            ->middleware(['permission:prescriptions.manage', 'throttle:sensitive', 'reauth']);
        Route::post('prescriptions/{prescription}/amend', [PrescriptionController::class, 'amend'])
            ->middleware(['permission:prescriptions.manage', 'throttle:sensitive', 'reauth']);

        Route::get('lab-orders', [LabOrderController::class, 'index'])->middleware('permission:prescriptions.view');
        Route::post('lab-orders', [LabOrderController::class, 'store'])->middleware('permission:prescriptions.manage');
        Route::put('lab-orders/{labOrder}', [LabOrderController::class, 'update'])->middleware('permission:prescriptions.manage');
        Route::post('lab-orders/{labOrder}/collect-sample', [LabOrderController::class, 'collectSample'])->middleware('permission:prescriptions.manage');
        Route::post('lab-orders/{labOrder}/verify', [LabOrderController::class, 'verify'])->middleware(['permission:lab.verify', 'reauth']);
        Route::post('lab-orders/{labOrder}/amend', [LabOrderController::class, 'amend'])->middleware(['permission:lab.verify', 'reauth']);
        Route::post('lab-orders/{labOrder}/finalize', [LabOrderController::class, 'finalize'])->middleware(['permission:lab.verify', 'reauth']);

        Route::post('dispensings', [DispensingController::class, 'store'])->middleware('permission:pharmacy.manage');
        Route::post('dispensings/{dispensing}/void', [DispensingController::class, 'void'])->middleware(['permission:pharmacy.manage', 'reauth']);

        Route::get('leads', [LeadController::class, 'index'])->middleware('permission:crm.view');
        Route::post('leads', [LeadController::class, 'store'])->middleware('permission:crm.manage');
        Route::get('leads/options', [LeadController::class, 'options'])->middleware('permission:crm.view');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->middleware('permission:crm.view');
        Route::put('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:crm.manage');
        Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:crm.manage');
        Route::patch('leads/{lead}/status', [LeadController::class, 'transition'])->middleware('permission:crm.manage');
        Route::post('leads/{lead}/communications', [LeadController::class, 'storeCommunication'])->middleware('permission:crm.manage');

        Route::get('followups', [FollowupController::class, 'index'])->middleware('permission:followups.view');
        Route::post('followups', [FollowupController::class, 'store'])->middleware('permission:followups.manage');
        Route::put('followups/{followup}', [FollowupController::class, 'update'])->middleware('permission:followups.manage');
        Route::post('followups/{followup}/complete', [FollowupController::class, 'complete'])->middleware('permission:followups.manage');
        Route::post('followups/{followup}/cancel', [FollowupController::class, 'cancel'])->middleware('permission:followups.manage');

        Route::get('claims', [ClaimController::class, 'index'])->middleware('permission:claims.view');
        Route::post('claims', [ClaimController::class, 'store'])->middleware('permission:claims.manage');
        Route::get('claims/{claim}', [ClaimController::class, 'show'])->middleware('permission:claims.view');
        Route::put('claims/{claim}', [ClaimController::class, 'update'])->middleware('permission:claims.manage');
        Route::delete('claims/{claim}', [ClaimController::class, 'destroy'])->middleware('permission:claims.manage');
        Route::patch('claims/{claim}/status', [ClaimController::class, 'transitionStatus'])->middleware('permission:claims.manage');

        Route::get('cdss/alerts', [CdssController::class, 'alerts'])->middleware('permission:cdss.view');
        Route::post('patients/{patient}/cdss/evaluate', [CdssController::class, 'evaluate'])->middleware('permission:cdss.manage');
        Route::patch('cdss/alerts/{alert}', [CdssController::class, 'acknowledge'])->middleware('permission:cdss.manage');

        Route::get('reference-data/readiness', [ReferenceDataController::class, 'readiness'])->middleware('permission:reference_data.approve');
        Route::get('reference-data/pending', [ReferenceDataController::class, 'pending'])->middleware('permission:reference_data.approve');
        Route::post('reference-data/approve/{type}/{id}', [ReferenceDataController::class, 'approve'])->middleware('permission:reference_data.approve');
        Route::post('reference-data/reject/{type}/{id}', [ReferenceDataController::class, 'reject'])->middleware('permission:reference_data.approve');

        Route::get('inventory', [InventoryController::class, 'index'])->middleware('permission:pharmacy.view');
        Route::get('inventory/transactions', [InventoryController::class, 'transactions'])->middleware('permission:pharmacy.view');
        Route::post('inventory/transactions', [InventoryController::class, 'store'])->middleware('permission:pharmacy.manage');
        Route::get('inventory/expiry-alerts', [InventoryController::class, 'expiryAlerts'])->middleware('permission:pharmacy.view');

        // Suppliers
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:suppliers.view');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.manage');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage');

        // Purchases
        Route::get('purchases', [PurchaseController::class, 'index'])->middleware('permission:purchases.view');
        Route::post('purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.manage');
        Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view');
        Route::put('purchases/{purchase}', [PurchaseController::class, 'update'])->middleware('permission:purchases.manage');
        Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy'])->middleware('permission:purchases.manage');
        Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->middleware('permission:purchases.manage');

        // Expense Categories
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('permission:expense_categories.view');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('permission:expense_categories.manage');
        Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->middleware('permission:expense_categories.manage');
        Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->middleware('permission:expense_categories.manage');

        // Expenses
        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view');
        Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.manage');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view');
        Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.manage');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:expenses.manage');
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.manage');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.manage');

        // AI content (strictly non-clinical; approval never auto-publishes)
        Route::get('ai-content/drafts', [AiContentDraftController::class, 'index'])->middleware('permission:ai_content.view');
        Route::post('ai-content/drafts', [AiContentDraftController::class, 'store'])->middleware('permission:ai_content.manage');
        Route::get('ai-content/drafts/{aiContentDraft}', [AiContentDraftController::class, 'show'])->middleware('permission:ai_content.view');
        Route::put('ai-content/drafts/{aiContentDraft}', [AiContentDraftController::class, 'update'])->middleware('permission:ai_content.manage');
        Route::delete('ai-content/drafts/{aiContentDraft}', [AiContentDraftController::class, 'destroy'])->middleware('permission:ai_content.manage');
        Route::post('ai-content/drafts/{aiContentDraft}/submit', [AiContentDraftController::class, 'submit'])->middleware('permission:ai_content.manage');
        Route::post('ai-content/drafts/{aiContentDraft}/review', [AiContentDraftController::class, 'review'])->middleware('permission:ai_content.manage');
        Route::post('ai-content/drafts/{aiContentDraft}/publish', [AiContentDraftController::class, 'publish'])->middleware('permission:ai_content.manage');

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports/financial', [ReportController::class, 'financial']);
            Route::get('reports/financial/export', [ReportController::class, 'exportFinancial']);
            Route::get('reports/visits', [ReportController::class, 'visits']);
            Route::get('reports/lab-tests', [ReportController::class, 'labTests']);
            Route::get('reports/low-stock', [ReportController::class, 'lowStock']);
            Route::get('reports/inventory/export', [ReportController::class, 'exportInventory']);
            Route::get('reports/analytics/revenue', [ReportController::class, 'revenueAnalytics']);
            Route::get('reports/analytics/utilization', [ReportController::class, 'utilizationAnalytics']);
            Route::get('reports/analytics/funnel', [ReportController::class, 'conversionAnalytics']);
            Route::get('reports/analytics/branch-volumes', [ReportController::class, 'branchVolumesAnalytics']);

            // Operational reports named in DESIGN §16 that the initial
            // analytics set did not cover. All accept the same `from`/`to`
            // filters; the expiry report is a point-in-time position.
            Route::get('reports/analytics/lab-turnaround', [ReportController::class, 'labTurnaround']);
            Route::get('reports/analytics/wait-times', [ReportController::class, 'waitTimes']);
            Route::get('reports/analytics/lead-conversion', [ReportController::class, 'leadConversion']);
            Route::get('reports/expiry', [ReportController::class, 'expiry']);
            Route::get('reports/expiry/export', [ReportController::class, 'exportExpiry']);
        });

        Route::middleware('permission:documents.view')->group(function () {
            Route::get('patients/{patient}/documents', [MedicalDocumentController::class, 'index']);
            Route::get('documents/{document}/download', [MedicalDocumentController::class, 'download']);
        });
        Route::post('patients/{patient}/documents', [MedicalDocumentController::class, 'store'])
            ->middleware('permission:documents.manage');

        Route::middleware('permission:documents.manage')->group(function () {
            Route::put('documents/{document}', [MedicalDocumentController::class, 'update']);
            Route::delete('documents/{document}', [MedicalDocumentController::class, 'destroy']);
        });

        Route::prefix('portal')->middleware('permission:portal.view')->group(function () {
            Route::get('appointments', [PortalController::class, 'appointments']);
            Route::post('appointments', [PortalController::class, 'requestAppointment']);
            Route::get('invoices', [PortalController::class, 'invoices']);
            Route::get('invoices/{invoice}/pdf', [PortalController::class, 'invoicePdf']);
            Route::get('prescriptions', [PortalController::class, 'prescriptions']);
            Route::get('prescriptions/{prescription}/pdf', [PortalController::class, 'prescriptionPdf']);
            Route::get('lab-results', [PortalController::class, 'labResults']);
            Route::get('notifications', [PortalController::class, 'notifications']);
            Route::get('consent', [PortalController::class, 'consent']);
            Route::put('consent', [PortalController::class, 'updateConsent'])->middleware('permission:portal.manage');

            Route::get('documents', [MedicalDocumentController::class, 'portalIndex']);
            Route::get('documents/{document}/download', [MedicalDocumentController::class, 'download']);
        });

        Route::post('portal/documents', [MedicalDocumentController::class, 'portalStore'])
            ->middleware('permission:portal.manage');

        // Compliance & Privacy (Admin only via policy)
        Route::post('compliance/export', [ComplianceController::class, 'requestExport']);
        Route::get('compliance/exports', [ComplianceController::class, 'listExports']);
        Route::get('compliance/exports/{export}', [ComplianceController::class, 'downloadExport']);
        Route::post('compliance/patients/{patient}/anonymize', [ComplianceController::class, 'anonymizePatient']);

        // System backups and administration (Admin only via policy)
        Route::get('backups', [BackupController::class, 'index']);
        Route::post('backups', [BackupController::class, 'store']);
        Route::get('backups/{backup}/download', [BackupController::class, 'download']);
        Route::delete('backups/{backup}', [BackupController::class, 'destroy']);
        Route::post('backups/warm-cache', [BackupController::class, 'warmCache']);

        Route::get('settings', [SystemSettingsController::class, 'index']);
        Route::post('settings', [SystemSettingsController::class, 'store']);
        Route::get('settings/{systemSetting}', [SystemSettingsController::class, 'show']);
        Route::put('settings/{systemSetting}', [SystemSettingsController::class, 'update']);
        Route::delete('settings/{systemSetting}', [SystemSettingsController::class, 'destroy']);

        // Telehealth (doctor / admin / patient, gated within the controller)
        Route::get('telehealth/sessions', [TelehealthController::class, 'index']);
        Route::post('telehealth/sessions', [TelehealthController::class, 'store']);
        Route::post('telehealth/sessions/{session}/messages', [TelehealthController::class, 'sendMessage']);
        Route::patch('telehealth/sessions/{session}/end', [TelehealthController::class, 'end']);

        // Notifications (self-service; scoped to the authenticated user)
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
        Route::get('notifications/channels', [NotificationController::class, 'channels'])->middleware('permission:notifications.view');
        Route::put('notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('permission:notifications.view');
        Route::put('notifications/channels/{channel}', [NotificationController::class, 'updateChannel'])->middleware('permission:notifications.manage');

        // Notification templates (DESIGN §15): draft → review → approve; unapproved
        // templates can never be sent and approving requires notifications.manage.
        Route::get('notification-templates', [NotificationTemplateController::class, 'index'])->middleware('permission:notifications.view');
        Route::post('notification-templates', [NotificationTemplateController::class, 'store'])->middleware('permission:notifications.manage');
        Route::get('notification-templates/{template}', [NotificationTemplateController::class, 'show'])->middleware('permission:notifications.view');
        Route::put('notification-templates/{template}', [NotificationTemplateController::class, 'update'])->middleware('permission:notifications.manage');
        Route::delete('notification-templates/{template}', [NotificationTemplateController::class, 'destroy'])->middleware('permission:notifications.manage');
        Route::post('notification-templates/preview', [NotificationTemplateController::class, 'preview'])->middleware('permission:notifications.view');
        Route::post('notification-templates/{template}/preview', [NotificationTemplateController::class, 'previewTemplate'])->middleware('permission:notifications.view');
        Route::post('notification-templates/{template}/approve', [NotificationTemplateController::class, 'approve'])->middleware('permission:notifications.manage');
        Route::post('notification-templates/{template}/reject', [NotificationTemplateController::class, 'reject'])->middleware('permission:notifications.manage');

        // Security / audit events (sensitive; admin-only surface)
        Route::get('security-events', [SecurityEventController::class, 'index'])->middleware('permission:audit.view');
    });
});
