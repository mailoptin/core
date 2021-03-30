var gulp = require('gulp');

gulp.task('optinrjs', function () {
    var requirejsOptimize = require('gulp-requirejs-optimize');
    return gulp.src('src/assets/js/src/main.js')
        .pipe(requirejsOptimize(function (file) {
            return {
                preserveLicenseComments: false,
                optimize: 'uglify',
                wrap: true,
                baseUrl: './src/assets/js/src',
                name: "almond",
                include: "main",
                out: "mailoptin.min.js"
            };
        }))
        .pipe(gulp.dest('src/assets/js'));
});

gulp.task('flowBuilderRjs', function () {
    var requirejsOptimize = require('gulp-requirejs-optimize');
    return gulp.src('src/assets/js/admin/flowbuilder/main.js')
        .pipe(requirejsOptimize(function (file) {
            return {
                preserveLicenseComments: false,
                optimize: 'uglify',
                wrap: true,
                baseUrl: './src/assets/js/admin/flowbuilder',
                name: "../../src/almond",
                include: "main",
                out: "flowbuilder.min.js"
            };
        }))
        .pipe(gulp.dest('src/assets/js'));
});

gulp.task('default', ['optinrjs', 'flowBuilderRjs']);