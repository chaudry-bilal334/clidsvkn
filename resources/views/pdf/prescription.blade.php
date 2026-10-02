<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Prescription {{ $prescription->prescription_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1e293b;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        .page { padding: 32px 40px; }
        .header { border-bottom: 3px solid #0d9488; padding-bottom: 16px; margin-bottom: 24px; }
        .brand { font-size: 22px; font-weight: 700; color: #0d9488; }
        .brand small { display: block; font-size: 10px; font-weight: 400; color: #64748b; }
        .meta { float: right; text-align: right; width: 260px; font-size: 11px; }
        .meta strong { display: block; font-size: 13px; color: #0f172a; }
        .meta div { margin-top: 2px; }
        .meta .label { color: #64748b; }
        h1 { margin: 0 0 4px; font-size: 16px; color: #0f172a; }
        .prescription-no { float: right; font-weight: 700; color: #0f172a; }
        table.parties { width: 100%; margin-bottom: 24px; border-collapse: collapse; }
        table.parties td { vertical-align: top; width: 33.33%; border: 1px solid #e2e8f0; padding: 12px 16px; }
        .heading { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin-bottom: 6px; }
        .value { font-weight: 700; font-size: 13px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.items th { background: #f1f5f9; text-transform: uppercase; font-size: 9px; letter-spacing: .06em; color: #64748b; text-align: left; padding: 8px 10px; }
        table.items td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; vertical-align: top; }
        table.items .medicine { font-weight: 700; }
        .notes { border: 1px dashed #cbd5e1; border-radius: 8px; padding: 12px 16px; margin-bottom: 40px; }
        .notes h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #0d9488; margin: 0 0 6px; }
        .signature { margin-top: 48px; }
        .signature table { width: 100%; }
        .signature td { width: 50%; }
        .sig-line { border-bottom: 1px solid #1e293b; height: 40px; width: 100%; }
        .sig-label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-top: 4px; }
        .doctor-name { font-weight: 700; margin-top: 6px; }
        .doctor-license { color: #64748b; font-size: 10px; }
        .footer { border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 10px; color: #64748b; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="brand">
                {{ $clinic['name'] }}
                <small>{{ $clinic['address'] }} · {{ $clinic['phone'] }}</small>
            </div>
            <div class="meta">
                <strong>MEDICAL PRESCRIPTION</strong>
                <div><span class="label">Date:</span> {{ $prescription->issued_at?->format('F j, Y') ?: $prescription->created_at?->format('F j, Y') }}</div>
                <div><span class="label">Policy:</span> {{ $prescription->prescription_number }}</div>
            </div>
            <div style="clear: both;"></div>
        </div>

        <table class="parties">
            <tr>
                <td>
                    <div class="heading">Patient</div>
                    <div class="value">{{ $prescription->patient_name ?? 'Walk-in Patient' }}</div>
                    @if ($prescription->patient_id)
                        <div class="heading" style="margin-top: 8px;">Patient ID</div>
                        <div>{{ $prescription->patient_id }}</div>
                    @endif
                </td>
                <td>
                    <div class="heading">Prescribed By</div>
                    <div class="value">{{ $prescription->doctor_name ?? 'Physician' }}</div>
                    <div class="heading" style="margin-top: 8px;">Status</div>
                    <div>{{ ucfirst($prescription->status) }}</div>
                </td>
                <td>
                    <div class="heading">Contact</div>
                    <div>{{ $clinic['phone'] }}</div>
                    <div>{{ $clinic['email'] }}</div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Medication</th>
                    <th>Dosage</th>
                    <th>Frequency</th>
                    <th>Duration</th>
                    <th>Instructions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($prescription->items as $item)
                    <tr>
                        <td class="medicine">{{ $item->medicine_name }}</td>
                        <td>{{ $item->dosage }}</td>
                        <td>{{ $item->frequency }}</td>
                        <td>{{ $item->duration }}</td>
                        <td>{{ $item->instructions }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No medications prescribed.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($prescription->notes)
            <div class="notes">
                <h3>Additional Notes</h3>
                <p>{{ $prescription->notes }}</p>
            </div>
        @endif

        <div class="signature">
            <table>
                <tr>
                    <td>
                        <div class="sig-line"></div>
                        <div class="sig-label">Signature / Seal</div>
                        <div class="doctor-name">{{ $prescription->doctor_name ?? '' }}</div>
                        @if ($prescription->doctor_license_no)
                            <div class="doctor-license">Medical License: {{ $prescription->doctor_license_no }}</div>
                        @endif
                    </td>
                    <td>&nbsp;</td>
                </tr>
            </table>
        </div>

        <div class="footer">
            This prescription is valid only when dispensed by a licensed pharmacy.
            Keep out of reach of children. Follow your clinician's dosage instructions precisely.
        </div>
    </div>
</body>
</html>