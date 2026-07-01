<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Validate & canonicalize a target descriptor.
 * @return array{type:string,id:int|string}|WP_Error
 */
function wpultra_fields_resolve_target(array $target) {
    $type = $target['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['post', 'user', 'term', 'options'], true)) {
        return new WP_Error('target_invalid', 'target.type must be one of: post, user, term, options', ['target' => $target]);
    }
    if ($type === 'options') {
        $id = $target['id'] ?? '';
        if ($id === null) { $id = ''; }
        if (!is_string($id) || ($id !== '' && !preg_match('/^[a-z0-9_-]+$/i', $id))) {
            return new WP_Error('target_invalid', 'target.id for options must be a slug [a-z0-9_-]+ or omitted', ['target' => $target]);
        }
        return ['type' => 'options', 'id' => $id];
    }
    $id = $target['id'] ?? null;
    if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
        return new WP_Error('target_invalid', "target.id must be a numeric {$type} id", ['target' => $target]);
    }
    return ['type' => $type, 'id' => (int) $id];
}

/**
 * Split a write map into atomic vs. complex (consent-wrapped) values.
 * Complex contract: ['value' => mixed, 'mode' => 'replace'].
 * @return array{atomic:array<string,mixed>,complex:array<string,array{value:mixed,mode:string}>}|WP_Error
 */
function wpultra_fields_normalize_batch(array $values) {
    $atomic = [];
    $complex = [];
    foreach ($values as $name => $val) {
        if (!is_string($name) || $name === '') {
            return new WP_Error('field_invalid', 'field names must be non-empty strings', ['field' => $name]);
        }
        // Consent-wrapped complex value.
        if (is_array($val) && array_key_exists('value', $val) && array_key_exists('mode', $val)) {
            if ($val['mode'] !== 'replace') {
                return new WP_Error('complex_consent', "field '{$name}': complex mode must be 'replace'", ['field' => $name]);
            }
            $complex[$name] = ['value' => $val['value'], 'mode' => 'replace'];
            continue;
        }
        // A value carrying a 'mode' key without a matching 'value' is a malformed consent wrapper.
        if (is_array($val) && (array_key_exists('mode', $val) || (array_key_exists('value', $val) && count($val) === 1 && !array_is_list($val)))) {
            return new WP_Error('complex_consent', "field '{$name}': complex values need { value, mode:'replace' }", ['field' => $name]);
        }
        $atomic[$name] = $val;
    }
    return ['atomic' => $atomic, 'complex' => $complex];
}
