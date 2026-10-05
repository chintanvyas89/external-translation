<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Computes stable comparison hashes for translated field values.
 */
interface TranslationHashManagerInterface {

  /**
   * Normalizes and hashes a value for comparison.
   *
   * Normalization collapses incidental whitespace differences (trailing
   * spaces, repeated blank lines, CRLF vs LF) so that saving a form without
   * an actual content change doesn't register as an editor modification.
   *
   * @param string|null $value
   *   The raw field value.
   *
   * @return string
   *   A hex hash of the normalized value. Empty string hashes to a fixed
   *   value so "no value" is comparable too.
   */
  public function hash(?string $value): string;

}
