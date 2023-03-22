const mix = require('laravel-mix');
const BundleAnalyzerPlugin = require('webpack-bundle-analyzer').BundleAnalyzerPlugin;

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

mix.js('resources/js/app.js', 'public/js')
  .sass('resources/sass/app.scss', 'public/css')
  .version();

if (mix.inProduction()) {
  mix.webpackConfig({
    output: {
      chunkFilename: '[name]-[chunkhash].js',
    },
    plugins: [new BundleAnalyzerPlugin({analyzerHost:'0.0.0.0'})],
  });
}else{
  mix.webpackConfig({
    plugins: [],
  });
}

const ServiceWorkerWebpackPlugin = require('serviceworker-webpack-plugin');

plugins: [
  new ServiceWorkerWebpackPlugin({
    entry: path.join(__dirname, './firebase-messaging-sw.js'),
  })
]
