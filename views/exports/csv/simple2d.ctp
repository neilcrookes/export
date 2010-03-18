<?php
// Add Byte Order Marker (BOM)
echo chr(255).chr(254);
foreach ($$dataVarName as $row) {
  // Add each row, fields enclosed by ", terminated by \t as per M$ Excel
  $line = '"'.implode("\"\t\"",$row).'"'."\n";
  if (Configure::read('App.encoding') != $charEncoding) {
    $line = mb_convert_encoding($line, $charEncoding, Configure::read('App.encoding'));
  }
  echo $line;
}
?>