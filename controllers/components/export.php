<?php
/**
 * CakePHP Component for exporting data.
 *
 * Can export data automatically just by attaching the component to a controller
 * and adding a supported extension to the URL, e.g. /<controller>.csv or can be
 * invoked manually from within a controller action if you require more control
 * over the run time configuration settings.
 *
 * If the data to be exported can be fetched by a single Model::find('all') call
 * you can let the component do it itself, or if you need something more complex
 * you can fetch the data and send it to the component for export when in manual
 * mode.
 *
 * If the component fetches the data, it fetches chunks at a time, kind of like
 * pagination, but it gets the data for all pages. It renders a regular view
 * file for each chunk which means that you can keep your view logic where it
 * should be - in a view, and also it can export massive data sets as it flushes
 * the output to the client after each page before getting the next one, kind of
 * like streaming the data.
 *
 * It uses the CakePHP core RequestHandler component and
 * Router::parseExtensions() to pickup the format of the export like csv from a
 * URL like /<controller>.csv and set the view folder to
 * views/controller/csv/action.ctp
 *
 * In auto mode, the export is triggered from the components startup() method,
 * i.e. before your controller's action, but you can specify that things like
 * the conditions and order options to be passed to Model::find('all') should be
 * copied from the Controller::paginate property, which can be hard coded and
 * amended if required in the Controller::beforeFilter().
 *
 * There are lots more configuration options such as the path to the view file
 * to be rendered and format of the file name of the export. See the component
 * initialize() methods DocBlock for full details.
 *
 * @author Neil Crookes <neil@neilcrookes.com>
 * @link http://www.neilcrookes.com
 * @copyright (c) 2010 Neil Crookes
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 * @package cake
 * @subpackage cake.base
 */
class ExportComponent extends Object {

  /**
   * The other components used by this component
   *
   * @var array
   * @access public
   */
  public $components = array('RequestHandler');

  /**
   * Default settings for the component
   *
   * @var array
   */
  protected $_defaults = array(
    'auto' => true,
    'find_options' => 'Controller::paginate',
    'fields' => null,
    'limit' => 500,
    'data_var_name' => null,
    'layout' => null,
    'view_file' => null,
    'file_name_format' => '%controllerName%-%conditions%-%dateTime%',
    'char_encoding' => 'UTF-16LE',
  );

  /**
   * The formats the component supports
   *
   * @var array
   * @access protected
   */
  protected $_formats = array(
    'csv' => array(
      'mime_type' => 'application/csv'
    )
  );

  /**
   * Runtime settings for the component
   *
   * @var array
   * @access protected
   */
  protected $_settings = array();

  /**
   * Called before Controller::beforeFilter(), stores reference to Controller
   * object and loads passed config into settings property
   *
   * @param AppController $controller
   * @param array $config Possible keys are:
   * - auto boolean If true, and the request extension is one of the allowed
   *   formats, the export will be triggered from ExportComponent::startup()
   *   which is after Controller::beforeFilter() but before Controller::<action>
   * - find_options mixed The options passed to Model::find('all'). Normally an
   *   array with keys for conditions, order etc. If set to the string
   *   'Controller::paginate', the options will be taken from the
   *   Controller::paginate property (both the options at the root level of the
   *   array, and the ones specific for the current Controller::modelCLass).
   *   N.B. If not supplying your own data and find_options is set to
   *   Controller::paginate, you can override the limit value using the limit
   *   configuration setting
   * - fields array Allows you to specify the model and the fields to be
   *   included in the export data. If not specified all fields in the
   *   controller's modelClass will be exported, but no associated model data
   *   will be, i.e. as if recursive was set to -1. If specified, you can omit
   *   some, control the order etc, and the contain option will automatically be
   *   set in the options param sent to Model::find('all'). In addition, you can
   *   specify label and decorator options that will be passed to the view. e.g.
   *   Consider the schema for an email_signups table with fields for id (int),
   *   first_name (string), last_name (string), email (string), source_id (int,
   *   foreignKey in belongsTo Source), optin (boolean), created (datetime), you
   *   might not want to export the id field, have the email field first, have a
   *   label called First name(s) for the first_name field, display the Source
   *   name field as opposed to the source_id, e.g. Newspaper, TV, Search engine
   *   etc, display yes or no instead of 1 or 0 for the optin field, and format
   *   the created column to something more human readable than a mysql datetime
   *   format, here is the fields configuration setting for this:
   *   ***
   *   var $components = array(
   *     'Export.Export' => array(
   *       'csv' => array(
   *         'fields' => array(
   *           'email',
   *           'first_name' => 'First name(s)',
   *           'last_name',
   *           'Source.name' => array('label' => 'Where did you hear about us?'),
   *           'optin' => array('decorator' => 'yesNo'),
   *           'created' => array('decorator' => array('dateFormat', 'F j, Y, g:i a')
   *         )
   *       )
   *     )
   *   );
   *   ***
   *   If the key is numeric, the component assumes the value is the field name
   *   and will humanise the field name for the label, else the key is the field
   *   name. If the value is a string, the component assumes it's a label. If
   *   you omit the model, the component assumes it's the controller's model.
   * - limit integer If not supplying your own data, the export component will
   *   grab it from the database for you in chunks and render each chunk
   *   individually so the script doesn't run out of memory. You can specify the
   *   number of records to fetch from the database at one time in the limit
   *   configuration setting.
   * - data_var_name string The name of the variable in the view that will
   *   contain the data / results from the Model::find() call. N.B. if this is
   *   not specified, it defaults to Inflector::variable(Controller::name) e.g.
   *   for a controller called EmailSignups it will be emailSignups as per
   *   CakePHP conventions.
   * - layout string If not supplying your own data, and using the paginate
   *   method, we call Controller::render() multiple times, once per chunk /
   *   page, so we can't render a layout each time. The layout configuration
   *   setting can be set to null for this reason, or if you are supplying your
   *   own data and want to wrap it in a layout, you can do that too.
   * - view_file string The default view file rendered will be the one
   *   corresponding to the current controllers action, in the current
   *   controllers view path but in the request extension subfolder, e.g. for a
   *   URL like /email_signups/index.csv the view file rendered will be in
   *   app/views/email_signups/csv/index.ctp. If you want to render a different
   *   view, specify it here. Should be relative to views folder, so to use the
   *   generic csv view bundled with this plugin, set it to the following:
   *   ../plugins/export/views/export/csv/export.ctp
   * - file_name_format string The ExportComponent sends a content disposition
   *   attachment header that forces the browser to download the output. The
   *   file_name_format configuration setting is used to specify the file name
   *   of the file to download so it's not the name of your script with a .csv
   *   on the end for example. You can fully specify a file name, or you can use
   *   up to 3 place holders that will be dynamically replaced based on settings
   *   at run time. These place holders are %controllerName%, which will be
   *   replaced by hyphenated Controller::name e.g. EmailSignups becomes
   *   email-signups, %conditions% gets replaced by a string representation of
   *   the conditions used to created the result set, e.g. created > 2010-01-01
   *   becomes created-2010-01-01 (not perfect I know, but at least you get a
   *   vague idea of the content in the export file), and lastly, %dateTime%
   *   which is replaced by the date / time in the format Y-m-d-h-i-s.
   * @return void
   * @access public
   */
  public function initialize(&$controller, $config = null) {

    $this->controller =& $controller;

    $this->_loadConfig($config);

  }

  /**
   * Called after Controller::beforeFilter(), triggers the export if extension
   * of current request is supported, and auto configuration setting for this
   * format is true.
   *
   * @param AppController $controller
   * @return void
   * @access public
   */
  public function startup() {

    $format = $this->RequestHandler->ext;

    // Bomb out if the format requested is not supported
    if (!array_key_exists($format, $this->_formats)) {
      return;
    }

    // Bomb out if the format requested is not supposed to export automatically
    if (!$this->_settings[$format]['auto']) {
      return;
    }

    $this->export(null, $format);

  }

  /**
   * Called by self::startup() if running automatically (only format param will
   * be passed) or from a controller action if running manually (any params can
   * be passed). This is the main method in the component, calling other methods
   * in the class. Performs various setup tasks, then once or several times
   * calls Controller::render and finally exits.
   * 
   * @param array $data The data to be exported, should be an array similar to
   * results from a Model::find('all') call.
   * @param string $format The format of the export file, e.g. csv
   * @param array $config The configuration settings for the export. If
   * specified when called manually, the options will be merged on top of the
   * ones set when the component is attached.
   * @return void
   * @access public
   */
  public function export($data = null, $format = null, $config = null) {

    if (!$format) {
      $format = $this->RequestHandler->ext;
    }

    $this->_format = $format;
    
    // Bomb out if the format requested is not supported
    if (!array_key_exists($this->_format, $this->_formats)) {
      return;
    }

    if ($config) {
      $this->_loadConfig($config);
    }

    // If running automatically, or if we want to use the default method for 
    // getting data and don't want to supply our own, we use the pagination 
    // method to output the data one chunk at a time. So we can't wrap the
    // content in a layout, i.e. we call render for each chunk, so we allow the
    // layout to be set in the config, which defaults to null.
    $this->controller->layout = $this->_settings[$this->_format]['layout'];

    // Get the character encoding you want the data in, and set this to be
    // available in the view
    $charEncoding = $this->_settings[$this->_format]['char_encoding'];
    if (!$charEncoding) {
      $this->_settings[$this->_format]['char_encoding'] = $charEncoding = Configure::read('App.encoding');
    }

    // Get the name of variable you want the data in, and set this to be
    // available in the view
    $dataVarName = $this->_settings[$this->_format]['data_var_name'];
    if (!$dataVarName) {
      $this->_settings[$this->_format]['data_var_name'] = $dataVarName = Inflector::variable($this->controller->name);
    }
    $this->controller->set(compact('charEncoding', 'dataVarName'));

    // Set the fields view variable which defines the column order, headings and
    // any decorators
//    $this->_setFields();

    // Send content disposition attachment and filename header, and content type
    // and charset, and no cache etc
    $this->_sendHeaders();

    // Set the time limit to 0 so we don't time out
    set_time_limit(0);

    if (!$data) {

      // Set the options to be passed to the Model::find('all') call such as
      // fields, conditions, order, contain, recursive, these can come from
      // Controller::paginate or be passed in configuration options
      $this->_setFindOptions();

      // Paginate through the result set rendering one chunk at a time so we
      // can export massive datasets without running out of memory
      $this->_paginate();

    } else {

      $this->_render($data, true);

    }

    // Turn any SQL output off
    Configure::write('debug', 0);
    exit();
    
  }

  /**
   * Interprets passed config, merges with defaults config and existing settings
   * that may already have been set if not running in automatic mode, and
   * applies to ExportComponent::_settings property.
   * 
   * @param array $config
   * @return void
   * @access protected
   */
  protected function _loadConfig($config) {

    if (!is_array($config)) {
      $config = array();
    }

    // Get settings for all formats, i.e. the elements whose keys at the top
    // level of the config array that are not supported formats, i.e. keys
    // in the ExportComponent::_formats property
    $settingsForAllFormats = array_diff_key($config, $this->_formats);

    // Set up the settings for each format based on the defaults, all format
    // settings and format specific settings.
    foreach ($this->_formats as $format => $null) {

      $formatSpecificSettings = array();

      // Specific format settings may already have been set if not running in
      // automatic mode
      if (isset($this->_settings[$format])) {
        $formatSpecificSettings = $this->_settings[$format];
      }

      // Merge any passed settings in $config, on top of any existing format
      // specific settings
      if (isset($config[$format])) {
        $formatSpecificSettings = Set::merge($formatSpecificSettings, $config[$format]);
      }

      // Merge format specific settings on top of settings for all formats and
      // defaults
      $this->_settings[$format] = Set::merge($this->_defaults, $settingsForAllFormats, $formatSpecificSettings);

    }

  }

  /**
   * Determines a file name and sets content disposition header as attachment
   * and specifies the filename to be used. Sends content type and charset
   * header and a few others too.
   *
   * @return void
   * @access protected
   */
  protected function _sendHeaders() {

    // If there was an error, don't send this header as we want the error to be
    // displayed in the browser
    //$Debugger = Debugger::getInstance();
    //if (!empty($Debugger->errors)) {
    //  return;
    //}

    // Set up the search and replace array
    $searchAndReplace = array(
      // Replace non word chars and hyphen characters with hyphens
      '/[^a-z0-9-]/i' => '-',
      // Replace multiple hyphen characters with single hyphens
      '/-{2,}/' => '-',
    );

    // Get the file name format from the settings array for this format
    $fileNameFormat = $this->_settings[$this->_format]['file_name_format'];

    // If file name format contains %controllerName%, replace it with the
    // underscored version of the current controller name
    if (strpos($fileNameFormat, '%controllerName%') !== false) {
      // Get the controller name
      $controllerName = Inflector::underscore($this->controller->name);
      $searchAndReplace = array(
        '/%controllerName%/' => $controllerName
      ) + $searchAndReplace;
    }

    // If file name format contains %conditions%, replace it with the conditions
    // (converted to a string) used to filter the results
    if (strpos($fileNameFormat, '%conditions%') !== false) {
      // Get the conditions as a string, if there are any
      $conditions = '';
      if (isset($this->_options['conditions'])) {
        $db =& ConnectionManager::getDataSource($this->controller->{$this->controller->modelClass}->useDbConfig);
        $conditions = $db->conditionKeysToString($this->_options['conditions'], false);
        $conditions = implode('-', $conditions);
      }
      $searchAndReplace = array(
        '/%conditions%/' => $conditions
      ) + $searchAndReplace;
    }

    // If file name format contains %dateTime%, replace it with the current
    // date/time of the export
    if (strpos($fileNameFormat, '%dateTime%') !== false) {
      $dateTime = date('Y-m-d-H-i-s');
      $searchAndReplace = array(
        '/%dateTime%/' => $dateTime
      ) + $searchAndReplace;
    }

    // Replace the placeholders in the file name format setting
    $fileName = preg_replace(array_keys($searchAndReplace), array_values($searchAndReplace), $fileNameFormat);

    // Lowercase the filename
    $fileName = strtolower($fileName);

    // Add the format extension
    $fileName .= '.'.$this->_format;

    header("Content-Type: {$this->_formats[$this->_format]['mime_type']}; charset=\"{$this->_settings[$this->_format]['char_encoding']}\"");
    header("Content-Disposition: attachment; filename=\"$fileName\"");
    header("Content-Transfer-Encoding: binary");
    header("Pragma: no-cache");
    header("Expires: 0");

  }

  /**
   * Sets the options to be passed in to the find('all') call
   *
   * @return void
   * @access protected
   */
  protected function _setFindOptions() {
    
    $this->_options = $this->_settings[$this->_format]['find_options'];

    // If desired, copy the find options from the Controller::paginate property.
    // This can be useful if you hard code things like the order, conditions
    // keys etc directly in the Controller::paginate property, and/or if you set
    // them automatically in other components initialize or startup methods or
    // manually in them (or your controller action if auto = false)
    if ($this->_options == 'Controller::paginate' && is_array($this->controller->paginate)) {
      $this->_options = $this->controller->paginate;
      if (is_array($this->controller->paginate[$this->controller->modelClass])) {
        $this->_options = array_merge($this->_options, $this->controller->paginate[$this->controller->modelClass]);
      }
    }

    // Defauly find options
    $defaults = array(
      'conditions' => null, 'fields' => null, 'joins' => array(),
      'limit' => null, 'offset' => null, 'order' => null, 'page' => null,
      'group' => null, 'callbacks' => true, 'contain' => array()
    );

    // Remove any extra keys that may have crept in
    $this->_options = array_intersect_key($this->_options, $defaults);

    $this->_options = array_merge($defaults, $this->_options);

    // If you use Controller::paginate, chances are the limit may be the default
    // for displaying paged results on the screen, e.g. 10 or 20. To render this
    // many at a time in the export is overkill, so you can override it in the
    // settings.
    if ($this->_settings[$this->_format]['limit']) {
      $this->_options = $this->_settings[$this->_format]['limit'];
    }

    // The remainder of the method is concerned with settings the fields and
    // contains keys in options.

    // If fields is already set, in the options already, we will use them in the
    // find call.
    if ($this->_options['fields']) {
      return;
    }

    // The configuration settings includes a fields key, with which you can
    // specify which fields to fetch from the db and formatting instructions
    // when rendering the export data. If the fields key is not set in the
    // find options, but it is in the configuration settings, we'll use the
    // configuration settings fields key to determine which fields to use in the
    // find options as well. So if it's not set here either, just return.
    if (!isset($this->_settings[$this->_format]['fields'])) {
      return;
    }

    foreach ($this->_settings[$this->_format]['fields'] as $field => $value) {

      // Fields can be specified as values or as keys with options as values, so
      // if field is numeric, i.e. the key is numeric, the field is in fact the
      // value
      if (is_numeric($field)) {
        $field = $value;
      }

      // If field does not contain a period, assume the controller's model
      if (strpos($field, '.') === false) {

        $field = $this->controller->modelClass . '.' . $field;

      } else { // Model is specified

        // Extract model part
        list($model) = explode('.', $field);

        // If model is not the controller's model and not already in contain
        // key in options property, add it
        if ($model != $this->controller->modelClass
        && !in_array($model, $this->_options['contain'])) {
          $this->_options['contain'][] = $model;
        }

      }

      // Add the field to the fields key in the options property
      $this->_options['fields'][] = $field;

    }

  }

  /**
   * Exports the data by repeatedly fetching one chunk of data at a time and
   * rendering the appropriate view.
   *
   * @return void
   * @access protected
   */
  protected function _paginate() {

    // While there are results, or you are on the first page
    while (($data = $this->controller->{$this->controller->modelClass}->find('all', $this->_options)) || $this->_options['page'] == 1) {

      // Determine whether on page 1, if true, the view should output the
      // headings in the first row.
      $page1 = $this->_options['page'] == 1;

      $this->_render($data, $page1);

      // Flush the output buffer
      flush();

      // Reset the controller output property
      $this->controller->output = null;

      // Incremenet the page
      $this->_options['page']++;

    }

  }

  /**
   * Sets data and page1 variables (to determine whether to print headings or
   * not) to be available in the view, and render the export view.
   * @param array $data The data to be passed to the view
   * @param boolean $page1 Whether on the first page
   * @return void
   * @access protected
   */
  protected function _render($data, $page1) {

    $this->controller->set(array(
      $this->_settings[$this->_format]['data_var_name'] => $data,
      'page1' => $page1,
    ));

    if ($this->_settings[$this->_format]['view_file']) {
      echo $this->controller->render(null, null, $this->_settings[$this->_format]['view_file']);
    } else {
      echo $this->controller->render();
    }

  }

  /**
   * Sets the fields view variable to that in the component settings, or if not
   * specified, determine from the model schema
   */
  protected function _setFields() {

    // Initialise the fields array
    $fields = array();

    // If fields are specified in the component settings
    if (isset($this->_settings[$this->RequestHandler->ext]['fields'])
    && !empty($this->_settings[$this->RequestHandler->ext]['fields'])) {

      // Iterate through the fields setting
      foreach ($this->_settings[$this->RequestHandler->ext]['fields'] as $field => $options) {

        // If $field is numeric and $options is a string, assume $options
        // is $field
        if (is_numeric($field)) {
          $options = array('field' => $options);
        // If $options is a string, assume it's the label
        } elseif (is_string($options)) {
          $options = array('field' => $field, 'label' => $options);
        // Else set options['field']
        } else {
          $options['field'] = $field;
        }

        // If field contains a period, split the model out
        if (strpos($options['field'], '.') !== false) {
          list($options['model'], $options['field']) = explode('.', $options['field']);
        } else {
          // Get the controller's model
          $options['model'] = $this->controller->modelClass;
        }

        // If the label key is not set, make the label the humanised field
        if (!isset($options['label'])) {
          $options['label'] = Inflector::humanize($options['field']);
        }

        // If model is not default model, prefix label with humanised model
        if ($options['model'] != $this->controller->modelClass) {
          $options['label'] = Inflector::humanize(Inflector::underscore($options['model'])) . ' ' . $options['label'];
        }

        // Add to the fields array
        $fields[] = $options;

      }

    // Fields setting is not specified, so use all fields in the model, and
    // humanize the labels
    } else {

      $model = $this->controller->modelClass;
      $schema = $this->controller->$model->schema();
      foreach ($schema as $field => $details) {
        $fields[] = array(
          'model' => $model,
          'field' => $field,
          'label' => Inflector::humanize($field)
        );
      }
    }

    // Set the fields view variable
    $this->controller->set(compact('fields'));

  }

}

?>