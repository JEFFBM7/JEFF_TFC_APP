<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bulletin — {{ $student->full_name }} — {{ $term->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 16px; }
        .header h1 { margin: 0 0 4px; font-size: 20px; color: #2563eb; }
        .header .meta { font-size: 11px; color: #6b7280; }
        .info { margin: 14px 0; }
        .info td { padding: 2px 8px 2px 0; }
        table.grades { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.grades th, table.grades td { border: 1px solid #d1d5db; padding: 6px 10px; text-align: left; }
        table.grades th { background: #f3f4f6; font-size: 11px; }
        .num { text-align: right; }
        .summary {
            margin-top: 16px; padding: 10px 14px;
            background: #eff6ff; border-left: 4px solid #2563eb;
        }
        .summary .big { font-size: 22px; font-weight: bold; color: #1d4ed8; }
        .footer { margin-top: 30px; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Bulletin scolaire</h1>
        <div class="meta">EduConnect — Complexe scolaire MALUNGA</div>
    </div>

    <table class="info">
        <tr>
            <td><strong>Élève :</strong></td>
            <td>{{ $student->full_name }}</td>
            <td><strong>Matricule :</strong></td>
            <td>{{ $student->registration_number ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Classe :</strong></td>
            <td>{{ $student->classroom?->full_name ?? '—' }}</td>
            <td><strong>Trimestre :</strong></td>
            <td>{{ $term->name }}</td>
        </tr>
        <tr>
            <td><strong>Année scolaire :</strong></td>
            <td colspan="3">{{ $term->schoolYear?->name ?? '—' }}</td>
        </tr>
    </table>

    <table class="grades">
        <thead>
            <tr>
                <th>Cours</th>
                <th class="num">Coef.</th>
                <th class="num">Évaluations</th>
                <th class="num">Moyenne /20</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($subjects as $row)
                <tr>
                    <td>{{ $row['subject']->name }}</td>
                    <td class="num">{{ number_format($row['coefficient'], 2, ',', ' ') }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                    <td class="num">
                        @if ($row['average'] !== null)
                            {{ number_format($row['average'], 2, ',', ' ') }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#9ca3af">Aucun cours associé à cette classe.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($period_averages->isNotEmpty())
        <table class="grades">
            <thead>
                <tr>
                    <th>Période du trimestre</th>
                    <th class="num">Moyenne /20</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($period_averages as $row)
                    <tr>
                        <td>{{ $row['period']->name }}</td>
                        <td class="num">
                            @if ($row['average'] !== null)
                                {{ number_format($row['average'], 2, ',', ' ') }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="summary">
        <div>Moyenne générale du trimestre</div>
        <div class="big">
            @if ($overall_average !== null)
                {{ number_format($overall_average, 2, ',', ' ') }} / 20
            @else
                Non calculée
            @endif
        </div>
    </div>

    @if (!empty($appreciation))
        <div style="margin-top: 16px; padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px;">
            <div style="font-weight: bold; margin-bottom: 4px; color: #374151;">Appréciation de l'enseignant principal</div>
            <div style="white-space: pre-wrap;">{{ $appreciation }}</div>
        </div>
    @endif

    <div class="footer">
        Document généré le {{ now()->format('d/m/Y H:i') }} par EduConnect.
    </div>
</body>
</html>
