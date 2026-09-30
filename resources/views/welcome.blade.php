<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Імпорт заявок — {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main>
            <h1>Імпорт заявок</h1>

            <p id="import-success" class="success" role="status" hidden></p>
            <ul id="import-errors" class="error" role="alert" hidden></ul>

            <form id="import-form" action="{{ url('/imports') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <label for="file">Файл заявок (.xlsx)</label>
                <input id="file" name="file" type="file" accept=".xlsx" required>
                <button type="submit">Імпортувати</button>
            </form>
        </main>
    </body>
</html>
