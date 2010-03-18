# Export Plugin for CakePHP

Can export massive datasets without running out of memory, by manually paging
through a result set then rendering the export view for the pages data and
flushing it to the output (browser, but could be extended to write to the file
system) for each chunk, say 500 records.

Currently support CSV files.

Usage:

1. // app/config/routes.php
Router::parseExtensions('csv');

2. // controllers/signups_controller.php
var $components = array('Export.Export');

3. // views/signups/csv/index.ctp
Write your own, but have a look at plugins/export/views/exports/csv/simple2d.ctp

4. Point your browser to /signups.csv

Check out comments in plugins/export/components/export.php for advanced usage.