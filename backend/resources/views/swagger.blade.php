<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <title>{{ $title ?? 'EduConnect API — Swagger UI' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css" />
    <link rel="icon" type="image/png" href="https://unpkg.com/swagger-ui-dist@5.17.14/favicon-32x32.png" sizes="32x32" />
    <style>
        html, body { margin: 0; background: #fafafa; }
        .topbar { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js"></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: @json($specUrl),
                dom_id: '#swagger-ui',
                deepLinking: true,
                docExpansion: 'list',
                defaultModelsExpandDepth: 1,
                persistAuthorization: true,
                tryItOutEnabled: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset.slice(1)
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: 'BaseLayout'
            });
        });
    </script>
</body>
</html>
