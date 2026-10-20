<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// the Apps Script the store owner pastes into the sheet (Extensions > Apps Script)
class script {

    const VERSION = '1.0.0';

    public static function code() {
        $sheet = settings::sheet();

        return strtr( self::TEMPLATE, array(
            '{{SITE}}'     => self::js( home_url() ),
            '{{ENDPOINT}}' => self::js( self::endpoint() ),
            '{{ROUTE}}'    => self::js( '/' . rest::NS . '/' ),
            '{{KEY}}'      => self::js( settings::secret() ),
            '{{TAB}}'      => self::js( $sheet ? $sheet['tab'] : '' ),
            '{{VERSION}}'  => self::VERSION,
        ) );
    }

    // a local site is out of Google's reach, so a tunnel URL can stand in for it:
    // define( 'ALSS_PUBLIC_URL', 'https://my-store.ngrok-free.app' ); in wp-config.php
    public static function public_url() {
        $url = defined('ALSS_PUBLIC_URL') ? (string) ALSS_PUBLIC_URL : '';
        return untrailingslashit( esc_url_raw( apply_filters( 'alss_public_url', $url ) ) );
    }

    public static function endpoint() {
        $endpoint = rest_url( rest::NS . '/' );
        $public   = self::public_url();
        $home     = untrailingslashit( home_url() );
        if ( $public && 0 === strpos( $endpoint, $home ) ) {
            $endpoint = $public . substr( $endpoint, strlen( $home ) );
        }
        return $endpoint;
    }

    // .local, .test, localhost and private IPs can't be reached from Google's servers
    public static function unreachable() {
        if ( self::public_url() ) {
            return false;
        }
        $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( 'local' === wp_get_environment_type() || 'localhost' === $host || preg_match( '/\.(local|test|localhost|invalid|example)$/', $host ) ) {
            return true;
        }
        return (bool) filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    // a value placed inside single quotes in the script
    private static function js( $value ) {
        return addcslashes( (string) $value, "\\'\n\r" );
    }

    const TEMPLATE = <<<'JS'
/**
 * SoftIcon Sheet Sync for WooCommerce
 * Sends your edits in this sheet to {{SITE}}
 *
 * Setup: click Save, choose "alssInstall" in the toolbar, click Run and allow access.
 * Keep the key below private, anyone with it can change your products.
 */
var ALSS = {
  endpoint: '{{ENDPOINT}}',
  route: '{{ROUTE}}',
  key: '{{KEY}}',
  tab: '{{TAB}}',
  version: '{{VERSION}}',
  batch: 200
};

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Sheet Sync')
    .addItem('Fetch from WooCommerce', 'alssFetch')
    .addItem('Send all rows to WooCommerce', 'alssSendAll')
    .addSeparator()
    .addItem('Turn on instant sync', 'alssInstall')
    .addToUi();
}

// adds the edit trigger, Google asks for permission the first time
function alssInstall() {
  var ss = SpreadsheetApp.getActive();
  ScriptApp.getProjectTriggers().forEach(function (trigger) {
    if (trigger.getHandlerFunction() === 'alssOnEdit') {
      ScriptApp.deleteTrigger(trigger);
    }
  });
  ScriptApp.newTrigger('alssOnEdit').forSpreadsheet(ss).onEdit().create();

  var res = alssPost('ping', { version: ALSS.version });
  var message = res && res.ok ? 'Instant sync is on. Edits now go straight to your store.' : 'The edit trigger was added, but the store could not be reached.';
  alssToast(message);
  Logger.log(message);
}

function alssOnEdit(e) {
  if (!e || !e.range) {
    return;
  }
  var sheet = e.range.getSheet();
  if (sheet.getName() !== ALSS.tab) {
    return;
  }

  var top = e.range.getRow();
  var height = e.range.getNumRows();
  var left = e.range.getColumn();
  var width = e.range.getNumColumns();
  var header = alssHeader(sheet);
  var idCol = header.indexOf('ID') + 1;

  if (top === 1) {
    alssToast('The header row is used for syncing, keep the column names as they are.');
  }

  // a single ID cell typed over would point the row at another product
  if (idCol && width === 1 && left === idCol) {
    if (height === 1 && e.oldValue !== undefined) {
      e.range.setValue(e.oldValue);
    }
    alssToast('The ID column links each row to its product and cannot be edited.');
    return;
  }

  var start = Math.max(top, 2);
  var count = top + height - start;
  if (count < 1) {
    return;
  }
  alssToast('Updating WooCommerce...', -1);
  var errors = alssSend(sheet, header, start, count, [left, left + width - 1]);
  if (errors === 0) {
    alssToast('Saved to WooCommerce.');
  } else if (errors > 0) {
    alssToast(errors + ' row(s) could not be saved, see the Sync status column.');
  }
}

function alssFetch() {
  var res = alssPost('export', {});
  if (res) {
    alssToast(res.message);
  }
}

function alssSendAll() {
  var sheet = SpreadsheetApp.getActive().getSheetByName(ALSS.tab);
  if (!sheet) {
    alssToast('The tab "' + ALSS.tab + '" was not found.');
    return;
  }
  var header = alssHeader(sheet);
  var last = sheet.getLastRow();
  var errors = 0;
  for (var start = 2; start <= last; start += ALSS.batch) {
    var count = Math.min(ALSS.batch, last - start + 1);
    alssToast('Sending rows ' + start + ' to ' + (start + count - 1) + ' of ' + last + '...', -1);
    var result = alssSend(sheet, header, start, count, null);
    if (result < 0) {
      return;
    }
    errors += result;
  }
  alssToast(errors ? errors + ' row(s) could not be saved, see the Sync status column.' : 'All rows sent to WooCommerce.');
}

// sends rows, writes the saved values and a status back, returns the number of failed rows or -1
function alssSend(sheet, header, start, count, cols) {
  var values = sheet.getRange(start, 1, count, header.length).getValues();
  var rows = values.map(function (cells, i) {
    return { row: start + i, values: cells };
  });
  var res = alssPost('update', { header: header, rows: rows, cols: cols });
  if (!res || !res.rows) {
    return -1;
  }

  var columns = {};
  var statusCol = header.indexOf('Sync status');
  var errors = 0;
  res.rows.forEach(function (item) {
    var i = item.row - start;
    if (i < 0 || i >= count) {
      return;
    }
    if (item.values) {
      header.forEach(function (name, c) {
        if (Object.prototype.hasOwnProperty.call(item.values, name)) {
          values[i][c] = item.values[name];
          columns[c] = true;
        }
      });
    }
    if (statusCol > -1) {
      values[i][statusCol] = item.message;
      columns[statusCol] = true;
    }
    if (item.status === 'error') {
      errors++;
    }
  });

  // only synced columns are written, formulas in your own columns stay untouched
  Object.keys(columns).forEach(function (c) {
    c = Number(c);
    sheet.getRange(start, c + 1, count, 1).setValues(values.map(function (cells) {
      return [alssText(cells[c])];
    }));
  });
  return errors;
}

// text from the store stays text, like valueInputOption RAW on the WordPress side
function alssText(value) {
  return typeof value === 'string' && /^[=+\-@]/.test(value) ? "'" + value : value;
}

function alssPost(route, data) {
  var body = JSON.stringify(data);
  var timestamp = String(Math.floor(Date.now() / 1000));
  var nonce = Utilities.getUuid();
  var signed = [timestamp, nonce, ALSS.route + route, body].join('.');
  var signature = Utilities.computeHmacSha256Signature(signed, ALSS.key, Utilities.Charset.UTF_8)
    .map(function (b) {
      return ('0' + (b & 255).toString(16)).slice(-2);
    })
    .join('');

  try {
    var response = UrlFetchApp.fetch(ALSS.endpoint + route, {
      method: 'post',
      contentType: 'application/json; charset=utf-8',
      payload: body,
      // the last header skips ngrok's browser warning page on tunnelled test sites, other servers ignore it
      headers: { 'X-ALSS-Timestamp': timestamp, 'X-ALSS-Nonce': nonce, 'X-ALSS-Signature': signature, 'ngrok-skip-browser-warning': '1' },
      muteHttpExceptions: true
    });
    var json = {};
    try {
      json = JSON.parse(response.getContentText());
    } catch (err) {}
    if (response.getResponseCode() !== 200) {
      alssToast('WooCommerce: ' + (json.message || 'error ' + response.getResponseCode()), 10);
      return null;
    }
    return json;
  } catch (err) {
    alssToast('Could not reach your store: ' + err.message, 10);
    return null;
  }
}

function alssHeader(sheet) {
  var width = Math.max(1, sheet.getLastColumn());
  return sheet.getRange(1, 1, 1, width).getValues()[0].map(function (name) {
    return String(name).trim();
  });
}

function alssToast(message, seconds) {
  SpreadsheetApp.getActive().toast(message, 'Sheet Sync', seconds || 5);
}
JS;
}
