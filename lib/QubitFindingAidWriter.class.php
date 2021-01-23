<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Generate a Finding Aid document (PDF or RTF format) from an information
 * object and it's descendants
 *
 * @package    AccesstoMemory
 * @author     Mike G <mikeg@artefactual.com>
 * @author     David Juhasz <djjuhasz@gmail.com>
 */

class QubitFindingAidWriter
{
  private
    $appRoot,
    $logger,
    $resource,
    $options;

  public function __construct(QubitInformationObject $resource, array $options = [])
  {
    // Check that $resource this is not the QubitInformationObject root
    if (QubitInformationObject::ROOT_ID === $resource->id)
    {
      throw new UnexpectedValueException(
        sprintf(
          'Invalid QubitInformationObject id: %s',
          QubitInformationObject::ROOT_ID
        )
      );
    }

    $this->resource = $resource;
    $this->options = $options;

    // Get AtoM application root directory
    $this->appRoot = rtrim(sfConfig::get('sf_root_dir'), '/');

    // $options['logger'] (if set), or default symfony logger
    $this->setLogger();
  }

  public function generate()
  {
    $this->logger->info(
      sprintf('Generating finding aid (%s)...', $this->resource->slug)
    );

    // Get EAD file path
    $eadFilePath = $this->getEadFilePath($eadFilePath);

    $foFileHandle = tmpfile();

    if (!$foFileHandle)
    {
      $this->error($this->i18n->__('Failed to create temporary file.'));

      return false;
    }

    $foFilePath = $this->getTmpFilePath($foFileHandle);

    try
    {
      $this->generateFop($eadFilePath, $foFilePath);
    }
    catch (Exception $e)
    {
      $this->error('Transforming the EAD with Saxon has failed.');
      $this->logCmdOutput($e->getMessage(), 'ERROR(SAXON)');

      return false;
    }

    // Use FOP generated in previous step to generate PDF
    $cmd = sprintf("fop -r -q -fo '%s' -%s '%s' 2>&1", $foFilePath, self::getFindingAidFormat(), $pdfPath);
    $this->info(sprintf('Running: %s', $cmd));
    $output = array();
    exec($cmd, $output, $exitCode);

    if ($exitCode != 0)
    {
      $this->error($this->i18n->__('Converting the EAD FO to PDF has failed.'));
      $this->logCmdOutput($output, 'ERROR(FOP)');

      return false;
    }

    // Update or create 'findingAidStatus' property
    $criteria = new Criteria;
    $criteria->add(QubitProperty::OBJECT_ID, $this->resource->id);
    $criteria->add(QubitProperty::NAME, 'findingAidStatus');

    if (null === $property = QubitProperty::getOne($criteria))
    {
      $property = new QubitProperty;
      $property->objectId = $this->resource->id;
      $property->name = 'findingAidStatus';
    }

    $property->setValue(self::GENERATED_STATUS, array('sourceCulture' => true));
    $property->indexOnSave = false;
    $property->save();

    // Update ES document with finding aid status
    $partialData = array(
      'findingAid' => array(
        'status' => self::GENERATED_STATUS
    ));

    QubitSearch::getInstance()->partialUpdate($this->resource, $partialData);

    $this->info($this->i18n->__('Finding aid generated successfully: %1', array('%1' => $pdfPath)));

    fclose($eadFileHandle); // Will delete the tmp file
    fclose($foFileHandle);

    return true;
  }

  /**
   * Get the EAD XML file path for this resource
   *
   * @return string EAD XML filepath
   */
  public function getEadFilepath()
  {
    // Check for a cached EAD file
    $cachedEadFilepath = $this->getCachedEadFilePath();

    if (!empty($cachedEadFilepath) && file_exists($cachedEadFilepath))
    {
      return $cachedEadFilepath;
    }

    // No cached file, so generate an EAD file
    return $this->generateEadFile();
  }

  /**
   * Generate an EAD XML file and return the file path
   *
   * @return string EAD XML file path
   */
  public function generateEadFile()
  {
    exportBulkBaseTask::includeXmlExportClassesAndHelpers();

    try
    {
      // Print warnings/notices here too, as they are often important.
      $errLevel = error_reporting(E_ALL);

      $rawXml = exportBulkBaseTask::captureResourceExportTemplateOutput(
        $this->resource, 'ead'
      );
      $xml = Qubit::tidyXml($rawXml);

      error_reporting($errLevel);
    }
    catch (Exception $e)
    {
      throw new sfException($this->i18n->__(
        "Error generating EAD XML for '%1%.'", ['%1%' => $this->resource->slug]
      ));
    }

    $filepath = null;

    // If XML caching is enabled, then cache the EAD XML file
    if (sfConfig::get('app_cache_xml_on_save', false))
    {
      $filepath = QubitInformationObjectXmlCache::resourceExportFilePath(
        $this->resource, self::XML_STANDARD
      );
    }

    if (empty($filepath))
    {
      $filename = exportBulkBaseTask::generateSortableFilename(
        $resource, 'xml', 'ead'
      );
      $filepath = $path . PATH_SEPARATOR . $filename;
    }

    if (false === file_put_contents($filepath, $xml))
    {
      throw new sfException($this->i18n->__(
        "ERROR (EAD-EXPORT): Couldn't write file: '%1%'", ['%1%' => $filepath]
      ));
    }

    return $filepath;
  }

  public function delete()
  {
    $this->info($this->i18n->__('Deleting finding aid (%1)...', array('%1' => $this->resource->slug)));

    foreach (self::getPossibleFilenames($this->resource->id) as $filename)
    {
      $path = sfConfig::get('sf_web_dir') . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . $filename;

      if (file_exists($path))
      {
        unlink($path);
      }
    }

    // Delete 'findingAidTranscript' property if it exists
    $criteria = new Criteria;
    $criteria->add(QubitProperty::OBJECT_ID, $this->resource->id);
    $criteria->add(QubitProperty::NAME, 'findingAidTranscript');
    $criteria->add(QubitProperty::SCOPE, 'Text extracted from finding aid PDF file text layer using pdftotext');

    if (null !== $property = QubitProperty::getOne($criteria))
    {
      $this->info($this->i18n->__('Deleting finding aid transcript...'));

      $property->indexOnDelete = false;
      $property->delete();
    }

    // Delete 'findingAidStatus' property if it exists
    $criteria = new Criteria;
    $criteria->add(QubitProperty::OBJECT_ID, $this->resource->id);
    $criteria->add(QubitProperty::NAME, 'findingAidStatus');

    if (null !== $property = QubitProperty::getOne($criteria))
    {
      $property->indexOnDelete = false;
      $property->delete();
    }

    // Update ES document removing finding aid status and transcript
    $partialData = array(
      'findingAid' => array(
        'transcript' => null,
        'status' => null
    ));

    QubitSearch::getInstance()->partialUpdate($this->resource, $partialData);

    $this->info($this->i18n->__('Finding aid deleted successfully.'));

    return true;
  }

  /**
   * Set an sfLogger
   *
   * @return void
   */
  private function setLogger()
  {
    // Set logger from options, if passed
    if (
      isset($this->options['logger']) &&
      $this->options['logger'] instanceof sfLogger
    )
    {
      $this->logger = $options['logger'];
    }
    else
    {
      // Get the default symfony logger
      $this->logger = sfContext::getInstance()->getLogger();
    }
  }

  private function getCachedEadFilePath()
  {
    return QubitInformationObjectXmlCache::resourceExportFilePath(
      $this->resource, self::XML_STANDARD
    );
  }

  private function upload($path)
  {
    $this->info($this->i18n->__('Uploading finding aid (%1)...', array('%1' => $this->resource->slug)));

    // Update or create 'findingAidStatus' property
    $criteria = new Criteria;
    $criteria->add(QubitProperty::OBJECT_ID, $this->resource->id);
    $criteria->add(QubitProperty::NAME, 'findingAidStatus');

    if (null === $property = QubitProperty::getOne($criteria))
    {
      $property = new QubitProperty;
      $property->objectId = $this->resource->id;
      $property->name = 'findingAidStatus';
    }

    $property->setValue(self::UPLOADED_STATUS, array('sourceCulture' => true));
    $property->indexOnSave = false;
    $property->save();

    $partialData = array(
      'findingAid' => array(
        'transcript' => null,
        'status' => self::UPLOADED_STATUS
    ));

    $this->info($this->i18n->__('Finding aid uploaded successfully: %1', array('%1' => $path)));

    // Extract finding aid transcript
    $mimeType = 'application/' . self::getFindingAidFormat();

    if (!QubitDigitalObject::canExtractText($mimeType))
    {
      $message = $this->i18n->__('Could not obtain finding aid text.');
      $this->job->addNoteText($message);
      $this->info($message);
    }
    else
    {
      $this->info($this->i18n->__('Obtaining finding aid text...'));

      $command = sprintf('pdftotext %s - 2> /dev/null', $path);
      exec($command, $output, $status);

      if ($status != 0)
      {
        $message = $this->i18n->__('Obtaining the text has failed.');
        $this->job->addNoteText($message);
        $this->info($message);
        $this->logCmdOutput($output, 'WARNING(PDFTOTEXT)');
      }
      else if (0 < count($output))
      {
        $text = implode(PHP_EOL, $output);

        // Truncate PDF text to <64KB to fit in `property.value` column
        $text = mb_strcut($text, 0, 65535);

        // Update or create 'findingAidTranscript' property
        $criteria = new Criteria;
        $criteria->add(QubitProperty::OBJECT_ID, $this->resource->id);
        $criteria->add(QubitProperty::NAME, 'findingAidTranscript');
        $criteria->add(QubitProperty::SCOPE, 'Text extracted from finding aid PDF file text layer using pdftotext');

        if (null === $property = QubitProperty::getOne($criteria))
        {
          $property = new QubitProperty;
          $property->objectId = $this->resource->id;
          $property->name = 'findingAidTranscript';
          $property->scope = 'Text extracted from finding aid PDF file text layer using pdftotext';
        }

        $property->setValue($text, array('sourceCulture' => true));
        $property->indexOnSave = false;
        $property->save();

        // Update partial data with transcript
        $partialData['findingAid']['transcript'] = $text;
      }
    }

    // Update ES document with finding aid status and transcript
    QubitSearch::getInstance()->partialUpdate($this->resource, $partialData);

    return true;
  }

  private function logCmdOutput(array $output, $prefix = null)
  {
    if (empty($prefix))
    {
      $prefix = 'ERROR: ';
    }
    else
    {
      $prefix = $prefix.': ';
    }

    foreach ($output as $line)
    {
      $this->error($prefix.$line);
    }
  }

  /**
   * Apache FOP requires certain namespaces to be included in the XML in order
   * to process it.
   */
  private function addEadNamespaces($filename, $url = null)
  {
    $content = file_get_contents($filename);

    $eadHeader = <<<EOL
<ead xmlns:ns2="http://www.w3.org/1999/xlink" xmlns="urn:isbn:1-931666-22-9"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
EOL;

    $content = preg_replace('(<ead .*?>|<ead>)', $eadHeader, $content, 1);

    file_put_contents($filename, $content);
  }

  private function getTmpFilePath($handle)
  {
    $meta_data = stream_get_meta_data($handle);

    return $meta_data['uri'];
  }

  private function renderXsl($filename, $vars)
  {
    // Get XSL file contents
    $content = file_get_contents($filename);

    // Replace placeholder vars (e.g. "{{ app_root }}")
    foreach($vars as $key => $val)
    {
      $content = str_replace("{{ $key }}", $val);
    }

    // Write contents to temp file for processing with Saxon
    $tmpFilePath = tempnam(sys_get_temp_dir(), 'ATM');
    file_put_contents($tmpFilePath, $content);

    return $tmpFilePath;
  }

  private function generateFop($eadFilePath, $foFilePath)
  {
    // Use XSL file selected in Finding Aid model setting
    $findingAidModel = 'inventory-summary';

    if (null !== $setting = QubitSetting::getByName('findingAidModel'))
    {
      $findingAidModel = $setting->getValue(array('sourceCulture' => true));
    }

    $saxonPath = $this->appRoot . '/lib/task/pdf/saxon9he.jar';
    $eadXslFilePath = sprintf(
      '%s/lib/task/pdf/ead-pdf-%s.xsl', $this->appRoot, $findingAidModel
    );

    // Add required namespaces to EAD header
    $this->addEadNamespaces($eadFilePath);

    // Replace {{ app_root }} placeholder var with the $this->appRoot value, and
    // return the temp XSL file path for Saxon processing
    $xslTmpPath = $this->renderXsl(
      $eadXslFilePath,
      ['app_root' => $this->appRoot]
    );

    // Transform EAD file with Saxon
    $pdfPath = sfConfig::get('sf_web_dir') . DIRECTORY_SEPARATOR .
      self::getFindingAidPath($this->resource->id);

    $cmd = sprintf(
      "java -jar '%s' -s:'%s' -xsl:'%s' -o:'%s' 2>&1",
      $saxonPath, $eadFilePath, $xslTmpPath, $foFilePath
    );

    $this->info(sprintf('Running: %s', $cmd));

    $output = array();
    $exitCode = null;

    exec($cmd, $output, $exitCode);

    if ($exitCode > 0)
    {
      throw new Exception($output);
    }
  }

  public static function getStatus($id)
  {
    $sql = '
      SELECT j.status_id as statusId FROM
      job j JOIN object o ON j.id = o.id
      WHERE j.name = ? AND j.object_id = ?
      ORDER BY o.created_at DESC
    ';

    $ret = QubitPdo::fetchOne($sql, array(get_class(), $id));

    return $ret ? (int)$ret->statusId : null;
  }

  public static function getPossibleFilenames($id)
  {
    $filenames = array(
      $id . '.pdf',
      $id . '.rtf'
    );

    if (null !== $slug = QubitSlug::getByObjectId($id))
    {
      $filenames[] = $slug->slug . '.pdf';
      $filenames[] = $slug->slug . '.rtf';
    }

    return $filenames;
  }

  public static function getFindingAidPathForDownload($id)
  {
    foreach (self::getPossibleFilenames($id) as $filename)
    {
      $path = 'downloads' . DIRECTORY_SEPARATOR . $filename;

      if (file_exists(sfConfig::get('sf_web_dir') . DIRECTORY_SEPARATOR . $path))
      {
        return $path;
      }
    }

    return null;
  }

  public static function getFindingAidPath($id)
  {
    if (null !== $slug = QubitSlug::getByObjectId($id))
    {
      $filename = $slug->slug;
    }

    if (!isset($filename))
    {
      $filename = $id;
    }

    return 'downloads' . DIRECTORY_SEPARATOR . $filename . '.' . self::getFindingAidFormat();
  }

  public static function getFindingAidFormat()
  {
    if (null !== $setting = QubitSetting::getByName('findingAidFormat'))
    {
      $format = $setting->getValue(array('sourceCulture' => true));
    }

    return isset($format) ? $format : 'pdf';
  }
}
