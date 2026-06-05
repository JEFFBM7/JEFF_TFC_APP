<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bulletin primaire — {{ $student->full_name }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 6.5px; color: #111; }
        .header { text-align: center; margin-bottom: 6px; }
        .header strong { display: block; font-size: 8px; }
        .meta { width: 100%; margin-bottom: 6px; font-size: 6.5px; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .title { text-align: center; font-weight: bold; font-size: 7.5px; border: 1px solid #000; padding: 4px; margin-bottom: 6px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td { border: 1px solid #000; padding: 1px 2px; text-align: center; }
        table.grid th.branch, table.grid td.branch { text-align: left; width: 16%; }
        .domain { background: #dbeafe; font-weight: bold; text-transform: uppercase; }
        .subdomain { background: #eff6ff; font-style: italic; font-weight: bold; }
        .subtotal { background: #f8fafc; font-weight: bold; }
        .score { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 8px; font-size: 6.5px; }
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
                <th colspan="5">PREMIER TRIMESTRE</th>
                <th colspan="5">DEUXIÈME TRIMESTRE</th>
                <th colspan="5">TROISIÈME TRIMESTRE</th>
                <th rowspan="3">TOTAL GÉNÉRAL</th>
            </tr>
            <tr>
                @for ($i = 0; $i < 3; $i++)
                    <th rowspan="2">MAX.</th>
                    <th colspan="2">TRAVAUX JOURNAL.</th>
                    <th rowspan="2">MAX. EXAM.</th>
                    <th rowspan="2">TOTAL</th>
                @endfor
            </tr>
            <tr>
                @for ($i = 0; $i < 3; $i++)
                    <th>1ère P</th><th>2ème P</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $row['kind'] }}">
                    <td class="branch">{{ $row['label'] }}</td>
                    <td>{{ $row['t1_max'] }}</td>
                    <td>{{ $row['t1_p1'] }}</td>
                    <td>{{ $row['t1_p2'] }}</td>
                    <td>{{ $row['t1_exam_max'] }}</td>
                    <td class="score">{{ $row['t1_total'] }}</td>
                    <td>{{ $row['t2_max'] }}</td>
                    <td>{{ $row['t2_p1'] }}</td>
                    <td>{{ $row['t2_p2'] }}</td>
                    <td>{{ $row['t2_exam_max'] }}</td>
                    <td class="score">{{ $row['t2_total'] }}</td>
                    <td>{{ $row['t3_max'] }}</td>
                    <td>{{ $row['t3_p1'] }}</td>
                    <td>{{ $row['t3_p2'] }}</td>
                    <td>{{ $row['t3_exam_max'] }}</td>
                    <td class="score">{{ $row['t3_total'] }}</td>
                    <td class="score">{{ $row['grand_total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <strong>Pourcentage annuel :</strong> {{ $percentage }}
        @if ($appreciation)
            <br><strong>Appréciation :</strong> {{ $appreciation }}
        @endif
    </div>

    <p class="note">
        NOTE IMPORTANTE : Il est formellement interdit de reproduire ce bulletin. Le bulletin est sans valeur s'il est raturé ou surchargé.
        <span style="float:right;">IGE/P.S./001</span>
    </p>
</body>
</html>
