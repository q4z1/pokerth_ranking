const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel application. By default, we are compiling the Sass
 | file for the application as well as bundling up all the JS files.
 |
 */

mix.vue()
    .sass('resources/sass/app.scss', 'public/css')
    .sass('resources/sass/countryflags.scss', 'public/css')
    .copy('resources/css/jquery.gorilla-dropdown.min.css', 'public/css/dd.css')
    .js('resources/js/injections.js', 'public/js')
