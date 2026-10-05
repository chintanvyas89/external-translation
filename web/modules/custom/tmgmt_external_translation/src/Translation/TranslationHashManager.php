<?php

namespace Drupal\tmgmt_external_translation\Translation;

class TranslationHashManager implements TranslationHashManagerInterface {

  public function hash(?string $value): string {
    $normalized = $this->normalize($value ?? '');
    return hash('sha256', $normalized);
  }

  /**
   * Collapses whitespace differences that don't reflect a real content
   * change. Deliberately simple for the POC: trims, unifies line endings,
   * and collapses runs of whitespace between HTML tags. Does not attempt
   * full HTML canonicalization (attribute order, etc.) - out of scope per
   * the field-type-scope decision for POC Phase 1/2.
   */
  protected function normalize(string $value): string {
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/>\s+</', '><', $value);
    $value = preg_replace('/[ \t]+/', ' ', $value);
    $value = preg_replace('/\n{2,}/', "\n", $value);
    return trim($value);
  }

}
