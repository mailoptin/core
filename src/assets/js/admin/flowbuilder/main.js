requirejs.config({
    baseUrl: mailoptin_globals.flow_builder_js_folder
});

if (typeof jQuery === 'function') {
    define('jquery', function () {
        return jQuery;
    });
}

define('mailoptin_globals', function () {
    return mailoptin_globals;
});


// Start the main app logic.
requirejs(['flowbuilder']);