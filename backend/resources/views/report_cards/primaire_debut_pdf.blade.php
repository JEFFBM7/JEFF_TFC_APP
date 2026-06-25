<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bulletin primaire — {{ $student->full_name }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 5.5px; color: #111; }
        .header { text-align: center; margin-bottom: 6px; }
        .header strong { display: block; font-size: 8px; }
        .meta { width: 100%; margin-bottom: 6px; font-size: 6.5px; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .title { text-align: center; font-weight: bold; font-size: 7.5px; border: 1px solid #000; padding: 4px; margin-bottom: 6px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td { border: 1px solid #000; padding: 1px 2px; text-align: center; }
        table.grid th.branch, table.grid td.branch { text-align: left; width: 14%; }
        .domain { background: #dbeafe; font-weight: bold; text-transform: uppercase; }
        .subdomain { background: #eff6ff; font-style: italic; font-weight: bold; }
        .subtotal { background: #f8fafc; font-weight: bold; }
        .score { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 8px; font-size: 6.5px; }
        .footer-grid { width: 100%; margin-top: 7px; font-size: 6px; }
        .footer-grid td { vertical-align: top; width: 33.33%; }
        .results { width: 70%; border-collapse: collapse; }
        .results caption { border: 1px solid #000; border-bottom: none; text-align: left; font-weight: bold; padding: 1px 2px; }
        .results th, .results td { border: 1px solid #000; padding: 1px 2px; text-align: center; }
        .results th:first-child, .results td:first-child { text-align: left; }
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
                <th rowspan="2" class="branch">BRANCHES</th>
                <th colspan="7">PREMIER TRIMESTRE</th>
                <th colspan="6">DEUXIÈME TRIMESTRE</th>
                <th colspan="6">TROISIÈME TRIMESTRE</th>
                <th colspan="2">TOTAL</th>
            </tr>
            <tr>
                <th>MAX per</th>
                <th>1ère P.</th><th>2e P.</th><th>MAX EX.</th><th>PTS OBT.</th><th>MAX TRIM.</th><th>PTS OBT.</th>
                <th>3e P.</th><th>4e P.</th><th>MAX EX.</th><th>PTS OBT.</th><th>MAX TRIM.</th><th>PTS OBT.</th>
                <th>5e P.</th><th>6e P.</th><th>MAX EX.</th><th>PTS OBT.</th><th>MAX TRIM.</th><th>PTS OBT.</th>
                <th>MAX</th><th>PTS OBT.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $row['kind'] }}">
                    <td class="branch">{{ $row['label'] }}</td>
                    <td>{{ $row['t1_max_per'] ?? '' }}</td>
                    <td>{{ $row['t1_p1'] ?? '' }}</td>
                    <td>{{ $row['t1_p2'] ?? '' }}</td>
                    <td>{{ $row['t1_exam_max'] ?? '' }}</td>
                    <td>{{ $row['t1_exam'] ?? '' }}</td>
                    <td>{{ $row['t1_trim_max'] ?? '' }}</td>
                    <td class="score">{{ $row['t1_total'] ?? '' }}</td>
                    <td>{{ $row['t2_p1'] ?? '' }}</td>
                    <td>{{ $row['t2_p2'] ?? '' }}</td>
                    <td>{{ $row['t2_exam_max'] ?? '' }}</td>
                    <td>{{ $row['t2_exam'] ?? '' }}</td>
                    <td>{{ $row['t2_trim_max'] ?? '' }}</td>
                    <td class="score">{{ $row['t2_total'] ?? '' }}</td>
                    <td>{{ $row['t3_p1'] ?? '' }}</td>
                    <td>{{ $row['t3_p2'] ?? '' }}</td>
                    <td>{{ $row['t3_exam_max'] ?? '' }}</td>
                    <td>{{ $row['t3_exam'] ?? '' }}</td>
                    <td>{{ $row['t3_trim_max'] ?? '' }}</td>
                    <td class="score">{{ $row['t3_total'] ?? '' }}</td>
                    <td>{{ $row['annual_max'] ?? '' }}</td>
                    <td class="score">{{ $row['grand_total'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="footer-grid">
        <tr>
            <td>
                @if (($gradeYear ?? null) === 6)
                    <table class="results">
                        <caption>RÉSULTATS</caption>
                        <thead>
                            <tr><th>Épreuve</th><th>Point</th><th>Sur</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Moyenne école</td><td></td><td>50</td></tr>
                            <tr><td>ENAFEP</td><td></td><td>50</td></tr>
                            <tr><td>Total</td><td></td><td>100</td></tr>
                        </tbody>
                    </table>
                @endif
                <div class="footer">
                    <strong>Pourcentage annuel :</strong> {{ $percentage }}
                    @if ($appreciation)
                        <br><strong>Appréciation :</strong> {{ $appreciation }}
                    @endif
                </div>
            </td>
            <td style="text-align:center;">Sceau de l'École</td>
            <td style="text-align:center;">Chef d'Établissement<br>Noms & signature</td>
        </tr>
    </table>

    <p class="note">
        NOTE IMPORTANTE : Il est formellement interdit de reproduire ce bulletin. Le bulletin est sans valeur s'il est raturé ou surchargé.
        <span style="float:right;">{{ $formCode ?? 'IGE/P.S./001' }}</span>
    </p>
</body>
</html>
