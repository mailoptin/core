requirejs.config({
    baseUrl: mailoptin_globals.flow_builder_js_folder
});

if (typeof jQuery !== 'undefined') {
    define('jquery', function () {
        return jQuery;
    });
}

if (typeof Backbone !== 'undefined') {
    define('backbone', function () {
        return Backbone;
    });
}

define('mailoptin_globals', function () {
    return mailoptin_globals;
});


// Start the main app logic.
requirejs(['flowbuilder']);