<?php

class PastaApi {
  protected $dataSet;

  public function __construct(EmlDataSet $dataSet) {
    $this->dataSet = $dataSet;
  }

  public function getDataSet() {
    return $this->dataSet();
  }

  public static function getApiUrl($path, array $options = array()) {
    $base_url = variable_get('eml_pasta_base_url', 'https://pasta.lternet.edu');
    return url($base_url . '/' . $path, $options);
  }

  public static function addApiAuthentication(array &$options) {
    $auth = token_replace(variable_get('eml_pasta_user', 'uid=[site:station-acronym],o=LTER,dc=ecoinformatics,dc=org'));
    $options['headers']['Authorization'] = 'Basic ' . base64_encode($auth);
  }

  public function submitEml() {
    $url = static::getApiUrl("package/eml");
    $options = array(
      'method' => 'POST',
      'data' => $this->dataSet->getEML(),
      'headers' => array(
        'Content-Type' => 'application/xml',
      ),
    );
    static::addApiAuthentication($options);
    $request = drupal_http_request($url, $options);

    // The API call to /package/eml returns a 202 on success with a transaction
    // ID which is used to fetch the actual evalution report from
    // /error/eml/{transaction}.
    if ($request->code == 202 && !empty($request->data)) {
      return $request->data;
    }
    elseif (!empty($request->error)) {
      throw new Exception(t('Unable to submit EML to @url: @error.', array('@url' => $url, '@error' => $request->error)));
    }
    else {
      throw new Exception(t('Unable to submit EML to @url.'));
    }
  }

  public function deleteEml() {
    list($scope, $identifier) = $this->dataSet->getPackageIDParts();
    $options = array();
    static::addApiAuthentication($options);
    $url = static::getApiUrl("eml/$scope/$identifier");
    $request = drupal_http_request($url, array('method' => 'DELETE'));
    // @todo Add some kind of exception handling here if the request did not succeed.
  }

  /**
   * Fetch a data set's DOI (data object ID) from the LTER Data Manager API.
   *
   * @return string
   *   A DOI string from the PASTA API.
   *
   * @throws Exception
   * @ingroup eml_data_manager_api
   */
  public function fetchDOI() {
    list($scope, $identifier, $revision) = $this->dataSet->getPackageIDParts();

    $url = static::getApiUrl("package/doi/eml/{$scope}/{$identifier}/{$revision}");
    $request = drupal_http_request($url, array('timeout' => 10));

    if (empty($request->error) && $request->code == 200 && !empty($request->data)) {
      if (preg_match('/doi:([\d.]+\/pasta\/[a-e0-9]+)/', $request->data, $matches)) {
        return $matches[1];
      }
      else {
        throw new Exception(t('DOI request to @url returned expected result %doi.', array('@url' => $url, '%doi' => $request->data)));
      }
    }
    elseif (!empty($request->error)) {
      throw new Exception(t('Unable to fetch EML DOI from @url: @error.', array('@url' => $url, '@error' => $request->error)));
    }
    else {
      throw new Exception(t('Unable to fetch EML DOI from @url.'));
    }
  }

  /**
   * Fetch an EML validation report transaction from the LTER Data Manager API.
   *
   * To evaluate an data set and its EML for validation:
   * - Upload the EML to https://pasta.lternet.edu/package/evaluate/eml
   * - The response from the API request should return a 202 HTTP status code
   *   on success, with the report transaction in the response body.
   * - The Data Package Manager API takes the EML and queues a report to be
   *   generated, which evaluates many different factors of the EML, including
   *   downloading the data files in the EML.
   * - Once the report has been finished, it can be fetched using
   *   EmlDataSet::fetchValidationReport().
   *
   * @throws Exception
   * @see PastaApi::fetchValidationReport()
   */
  public function fetchValidationReportTransaction() {
    // First we need to send the EML to the evaluation API.
    $url = static::getApiUrl('package/evaluate/eml');
    $options = array(
      'method' => 'POST',
      'data' => $this->dataSet->getEML(),
      'headers' => array(
        'Content-Type' => 'application/xml',
      ),
      'timeout' => 30,
    );
    $response = drupal_http_request($url, $options);

    // The API call to /evaluate/eml returns a 202 on success with a transaction
    // ID which is used to fetch the actual evalution report from
    // /evaluate/report/eml/{transaction}.
    if ($response->code == 202 && !empty($response->data)) {
      return $response->data;
    }
    elseif (!empty($response->error)) {
      throw new Exception(t('Unable to fetch EML validation report transaction from @url: @error.', array('@url' => $url, '@error' => $response->error)));
    }
    else {
      throw new Exception(t('Unable to fetch EML validation report transaction from @url.'));
    }
  }

  /**
   * Fetch an EML validation report from the LTER Data Manager API.
   *
   *
   * @throws Exception
   * @see PastaApi::fetchValidationReportTransaction()
   */
  public static function fetchValidationReport($transaction) {
    // Fetch the evaluation report from the API.
    $transaction = $request->data;
    $url = static::getApiUrl("package/evaluate/report/eml/{$transaction}");
    $request = drupal_http_request($url, array('timeout' => 10));

    // The report API on success returns a 200 response with the report XML
    // in the response body.
    if ($request->code == 200 && !empty($request->data)) {
      return static::parseEvaluationSummary($request->data);
    }
    elseif ($request->code == 401) {
      // A 401 respose means the report is not found, or is still being
      // generated.
      return array();
    }

    // Do not return anything if we could not fetch validity. We need to be able
    // to distinguish between valid (TRUE), invalid (FALSE), and unknown (NULL).
  }

  /**
   * Parse an EML evalution report into a summary.
   *
   * @param string $report_xml
   *   An EML evalution report XML in string form.
   *
   * @return array
   *   An array of the summary of the report, keyed by the strings 'valid',
   *   'info', 'warn', and 'error', and the values being the number of checks
   *   that were info valid, information, warnings, or errors, repsectively.
   */
  public static function parseEvaluationSummary($xml) {
    $results = array_fill_keys(array('valid', 'info', 'warn', 'error'), 0);
    $report = simplexml_load_string($xml);
    foreach ($report->datasetReport->qualityCheck as $check) {
      $results[(string) $check->status]++;
    }
    foreach ($report->entityReport->qualityCheck as $check) {
      $results[(string) $check->status]++;
    }
    return $results;
  }
}
