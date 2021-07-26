<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="internals">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="/pthranking/css/app.css" rel="stylesheet">
        <title>PokerTH Internal</title>
    </head>
    <body>
      <div id="vue1"><internals-component :authenticated="{{json_encode(!is_null(auth()->user()), true)}}"></internals-component></div>
    </body>
    <script type="text/javascript" src="/pthranking/js/pth.js"></script>
</html>
