/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

require('./bootstrap')
import Vue from 'vue'

// bootstrap-vue
// import { BootstrapVue, IconsPlugin } from 'bootstrap-vue'
// Vue.use(BootstrapVue)
// Vue.use(IconsPlugin)
//import 'bootstrap/dist/css/bootstrap.css'
// import 'bootstrap-vue/dist/bootstrap-vue.css'

import GLightbox from 'glightbox'
import 'glightbox/dist/css/glightbox.min.css'
import InfiniteLoading from 'vue-infinite-loading';
import ElementUI from 'element-ui'
import 'element-ui/lib/theme-chalk/index.css'

window.GLightbox = GLightbox
window.Vue = Vue

Vue.use(ElementUI)

import lang from 'element-ui/lib/locale/lang/en'
import locale from 'element-ui/lib/locale'
locale.use(lang)

import { DataTables, DataTablesServer  } from 'vue-data-tables'
Vue.use(DataTables)
Vue.use(DataTablesServer)

Vue.use(InfiniteLoading, { /* options */ })



/**
 * The following block of code may be used to automatically register your
 * Vue components. It will recursively scan this directory for the Vue
 * components and automatically register them with their "basename".
 *
 * Eg. ./components/ExampleComponent.vue -> <example-component></example-component>
 */

const files = require.context('./', true, /\.vue$/i)
files.keys().map(key => Vue.component(key.split('/').pop().split('.')[0], files(key).default))

// Vue.component('example-component', require('./components/ExampleComponent.vue').default);

/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */
if(document.getElementById('vue1')){
    new Vue({
        el: '#vue1',
    });
}

if(document.getElementById('vue2')){
    new Vue({
        el: '#vue2',
    });
}

if(document.getElementById('vue3')){
    new Vue({
        el: '#vue3',
    });
}

if(document.getElementById('vue4')){
    new Vue({
        el: '#vue4',
    });
}