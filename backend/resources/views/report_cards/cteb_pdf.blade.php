<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bulletin CTEB — {{ $student->full_name }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7px; color: #111; }
        .header { text-align: center; margin-bottom: 6px; }
        .header strong { display: block; font-size: 9px; }
        .meta { width: 100%; margin-bottom: 6px; font-size: 7px; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .title { text-align: center; font-weight: bold; font-size: 8px; border: 1px solid #000; padding: 4px; margin-bottom: 6px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td { border: 1px solid #000; padding: 2px 3px; text-align: center; }
        table.grid th.branch, table.grid td.branch { text-align: left; width: 22%; }
        .domain { background: #dbeafe; font-weight: bold; text-transform: uppercase; }
        .subdomain { background: #eff6ff; font-style: italic; font-weight: bold; }
        .subtotal { background: #f8fafc; font-weight: bold; }
        .score { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 8px; font-size: 7px; }
        .note { margin-top: 6px; text-align: center; font-weight: bold; font-size: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <strong>RÉPUBLIQUE DÉMOCRATIQUE DU CONGO</strong>
        <span>MINISTÈRE DE L'ÉDUCATION NATIONALE ET NOUVELLE CITOYENNETÉ</span>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Élève :</strong> {{ $student->full_name }}</td>
            <td><strong>Sexe :</strong> {{ $student->gender === 'F' ? 'F' : ($student->gender === 'M' ? 'M' : '—') }}</td>
            <td><strong>Classe :</strong> {{ $student->classroom?->full_name ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Matricule :</strong> {{ $student->registration_number ?? '—' }}</td>
            <td><strong>N° Perm. :</strong> {{ $student->order_number ?? '—' }}</td>
            <td><strong>École :</strong> Complexe scolaire MALUNGA</td>
        </tr>
    </table>

    <div class="title">
        {{ $bulletinTitle }}<br>
        ANNÉE SCOLAIRE {{ str_replace('-', ' – ', $schoolYearName) }}
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th rowspan="3" class="branch">BRANCHES</th>
                <th colspan="5">PREMIER SEMESTRE</th>
                <th colspan="5">SECOND SEMESTRE</th>
                <th rowspan="3">TOTAL GÉNÉRAL</th>
                <th colspan="2" rowspan="2">EXAMEN DE REPECHAGE</th>
            </tr>
            <tr>
                <th rowspan="2">MAX.</th>
                <th colspan="2">TRAVAUX JOURNAL.</th>
                <th rowspan="2">MAX. EXAM.</th>
                <th rowspan="2">TOTAL</th>
                <th rowspan="2">MAX.</th>
                <th colspan="2">TRAVAUX JOURNAL.</th>
                <th rowspan="2">MAX. EXAM.</th>
                <th rowspan="2">TOTAL</th>
            </tr>
            <tr>
                <th>1ère P</th><th>2ème P</th><th>3ème P</th><th>4ème P</th><th>%</th><th>Sign.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $row['kind'] }}">
                    <td class="branch">{{ $row['label'] }}</td>
                    <td>{{ $row['s1_max'] }}</td>
                    <td>{{ $row['s1_p1'] }}</td>
                    <td>{{ $row['s1_p2'] }}</td>
                    <td>{{ $row['s1_exam_max'] }}</td>
                    <td class="score">{{ $row['s1_total'] }}</td>
                    <td>{{ $row['s2_max'] }}</td>
                    <td>{{ $row['s2_p1'] }}</td>
                    <td>{{ $row['s2_p2'] }}</td>
                    <td>{{ $row['s2_exam_max'] }}</td>
                    <td class="score">{{ $row['s2_total'] }}</td>
                    <td class="score">{{ $row['grand_total'] }}</td>
                    <td></td><td></td>
                </tr>
            @endforeach
            <tr>
                <td class="branch"><strong>POURCENTAGE</strong></td>
                <td colspan="10"></td>
                <td class="score"><strong>{{ $percentage }}</strong></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    @if (!empty($appreciation))
        <div class="footer"><strong>Appréciation :</strong> {{ $appreciation }}</div>
    @endif

    <div class="note">NOTE IMPORTANTE : Le bulletin est sans valeur s'il est raturé ou surchargé.</div>
</body>
</html>
