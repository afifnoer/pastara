<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $appName }} — Dokumentasi REST API</title>
    <style>
        body {
            margin: 0;
            padding: 0;
        }
    </style>
</head>
<body>
    <script
        id="api-reference"
        data-url="{{ $specUrl }}"
        data-configuration='{
            "theme": "kepler",
            "layout": "modern",
            "showSidebar": true,
            "hideDownloadButton": false,
            "defaultHttpClient": {
                "targetKey": "js",
                "clientKey": "fetch"
            },
            "authentication": {
                "preferredSecurityScheme": "bearerAuth"
            }
        }'></script>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</body>
</html>
