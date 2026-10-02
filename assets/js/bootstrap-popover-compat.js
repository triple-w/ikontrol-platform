(function ($, bootstrap) {
    "use strict";
    if (!$ || !bootstrap || !bootstrap.Popover || $.fn.popover) {
        return;
    }

    // Compatibility bridge for legacy RISE callers running on Bootstrap 5.
    $.fn.popover = function (option) {
        var args = Array.prototype.slice.call(arguments, 1);
        return this.each(function () {
            var instance = bootstrap.Popover.getOrCreateInstance(
                this,
                typeof option === "object" ? option : {}
            );
            if (typeof option === "string" && typeof instance[option] === "function") {
                instance[option].apply(instance, args);
            }
        });
    };
})(window.jQuery, window.bootstrap);
