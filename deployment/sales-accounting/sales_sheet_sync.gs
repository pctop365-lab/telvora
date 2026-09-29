// Bound to the existing TELVORA accounting spreadsheet.
// Script properties: EXPORT_URL, EXPORT_TOKEN. The token never appears in cells.
const SALES_SHEET = 'Продажи';
const ITEMS_SHEET = 'Позиции';

function syncSales() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) throw new Error('Синхронизация уже выполняется');
  try {
    const props = PropertiesService.getScriptProperties();
    const url = props.getProperty('EXPORT_URL');
    const token = props.getProperty('EXPORT_TOKEN');
    if (!url || !/^https:\/\/telvora\.ru\/sales_export\.php$/.test(url) ||
        !token || token.length < 32) throw new Error('Настройте EXPORT_URL и EXPORT_TOKEN');
    const response = UrlFetchApp.fetch(url, {
      method: 'get', headers: {'X-Telvora-Export-Token': token},
      muteHttpExceptions: true, followRedirects: false
    });
    if (response.getResponseCode() !== 200)
      throw new Error('Выгрузка недоступна: HTTP ' + response.getResponseCode());
    const data = JSON.parse(response.getContentText());
    if (data.success !== true || !Array.isArray(data.orders) ||
        !Array.isArray(data.items)) throw new Error('Неверный ответ выгрузки');
    const book = SpreadsheetApp.openById('1VeN_Ibdp_eHXuo3yBzzzAYUARRhvU6IZjtshRyVpBPw');
    const sales = book.getSheetByName(SALES_SHEET);
    const positions = book.getSheetByName(ITEMS_SHEET);
    const settings = book.getSheetByName('Настройки');
    if (!sales || !positions || !settings) throw new Error('Не найдены листы учета');
    const taxCell = settings.getRange('B2').getValue();
    const taxRate = Number(taxCell);
    if (taxCell === '' || taxCell === null || !(taxRate >= 0 && taxRate < 1))
      throw new Error('Проверьте ставку в Настройки!B2');
    const salesRows = existingRows_(sales, 21);
    const positionRows = existingRows_(positions, 19);
    const salesIds = idMap_(salesRows, 'Продажи');
    const positionIds = idMap_(positionRows, 'Позиции');
    const orderById = new Map(data.orders.map(o => [String(o.id), o]));
    if (orderById.size !== data.orders.length ||
        new Set(data.items.map(i => String(i.id))).size !== data.items.length)
      throw new Error('В выгрузке повторяются ID');
    const purchaseByOrder = new Map();
    const namesByOrder = new Map();
    const positionsToWrite = [];
    for (const item of data.items) {
      const id = String(item.id);
      const orderId = String(item.order_id);
      if (!orderById.has(orderId)) throw new Error('Позиция без заказа: ' + id);
      const row = positionIds.get(id) || nextRow_(positions, positionIds);
      const old = positionRows[row - 2] || [];
      const qty = money_(item.quantity) || 0;
      const unitSale = money_(item.price) || 0;
      const manualCost = old[11] === '' || old[11] == null ? null : money_(old[11]);
      if (old[11] !== '' && old[11] != null && manualCost === null)
        throw new Error('Некорректная закупка вручную, строка Позиции!' + row);
      const snapshotCost = item.currency_code === 'RUB' ? money_(item.purchase_price) : null;
      const unitCost = manualCost === null ? snapshotCost : manualCost;
      const supplier = old[7] || item.supplier_name || '';
      const source = manualCost !== null ? 'Вручную' :
        snapshotCost !== null ? 'Снимок при заказе' :
        item.currency_code && item.currency_code !== 'RUB' ? 'Проверить валюту' :
        'Нужна проверка закупки';
      const totalCost = unitCost === null ? '' : round_(unitCost * qty);
      const dates = orderById.get(orderId);
      positionsToWrite.push({
        row,
        left: [id, orderId, safe_(item.order_number), safe_(item.product_name),
          qty, unitSale, round_(qty * unitSale), safe_(supplier),
          safe_(item.supplier_sku), item.supplier_offer_id_at_order || '',
          snapshotCost === null ? '' : snapshotCost],
        right: [totalCost, totalCost === '' ? '' : round_(qty * (unitSale - unitCost)),
          date_(dates.created_at), date_(dates.completed_at), source]
      });
      const current = purchaseByOrder.get(orderId) || {sum: 0, missing: false};
      if (totalCost === '') current.missing = true;
      else current.sum += totalCost;
      purchaseByOrder.set(orderId, current);
      const names = namesByOrder.get(orderId) || [];
      names.push(String(item.product_name) + ' × ' + qty);
      namesByOrder.set(orderId, names);
    }
    writeParts_(positions, positionsToWrite, [{col: 1, key: 'left'}, {col: 13, key: 'right'}]);
    const salesToWrite = [];
    for (const order of data.orders) {
      const id = String(order.id);
      const row = salesIds.get(id) || nextRow_(sales, salesIds);
      const old = salesRows[row - 2] || [];
      const products = money_(order.subtotal) || 0;
      const services = money_(order.services_total) || 0;
      const delivery = money_(order.delivery_price);
      const total = money_(order.total);
      const purchase = purchaseByOrder.get(id);
      const cost = purchase && !purchase.missing ? round_(purchase.sum) : '';
      const actual = [15, 16, 17].map(i => old[i] === '' || old[i] == null
        ? 0 : money_(old[i]));
      if (actual.some(x => x === null))
        throw new Error('Некорректные расходы, строка Продажи!' + row);
      const completed = order.status === 'Выполнен' && cost !== '' && total !== null;
      const beforeTax = completed ? round_(total - cost - actual.reduce((a, b) => a + b, 0)) : '';
      const tax = completed ? round_(total * taxRate) : '';
      salesToWrite.push({
        row,
        left: [id, safe_(order.order_number), date_(order.created_at),
          date_(order.completed_at), safe_(order.status), safe_(order.customer_name),
          safe_(order.phone), safe_(order.email),
          safe_((namesByOrder.get(id) || []).join('; ')),
          safe_(order.payment_method), products, services,
          delivery === null ? '' : delivery, total === null ? '' : total, cost],
        right: [beforeTax, tax, completed ? round_(beforeTax - tax) : '']
      });
    }
    writeParts_(sales, salesToWrite, [{col: 1, key: 'left'}, {col: 19, key: 'right'}]);
    sales.getRange('C2:D' + Math.max(2, sales.getLastRow())).setNumberFormat('dd.MM.yyyy HH:mm');
    positions.getRange('O2:P' + Math.max(2, positions.getLastRow())).setNumberFormat('dd.MM.yyyy HH:mm');
    console.log('Обновлено: заказов ' + data.orders.length + ', позиций ' + data.items.length);
  } finally {
    lock.releaseLock();
  }
}

function installSalesSyncTrigger() {
  for (const trigger of ScriptApp.getProjectTriggers()) {
    if (trigger.getHandlerFunction() === 'syncSales') ScriptApp.deleteTrigger(trigger);
  }
  ScriptApp.newTrigger('syncSales').timeBased().everyMinutes(5).create();
}

function existingRows_(sheet, width) {
  const last = sheet.getLastRow();
  return last < 2 ? [] : sheet.getRange(2, 1, last - 1, width).getValues();
}

function idMap_(rows, title) {
  const ids = new Map();
  rows.forEach((row, i) => {
    if (row[0] === '' || row[0] == null) return;
    const id = String(row[0]);
    if (ids.has(id)) throw new Error('Повтор ID в ' + title + ': ' + id);
    ids.set(id, i + 2);
  });
  return ids;
}

function nextRow_(sheet, ids) {
  let last = Math.max(sheet.getLastRow(), 1);
  for (const index of ids.values()) last = Math.max(last, index);
  const row = last + 1;
  if (row > sheet.getMaxRows()) sheet.insertRowsAfter(sheet.getMaxRows(), row - sheet.getMaxRows());
  ids.set('__row_' + row, row);
  return row;
}

function writeParts_(sheet, records, blocks) {
  const sorted = records.slice().sort((a, b) => a.row - b.row);
  for (const block of blocks) {
    for (let i = 0; i < sorted.length;) {
      const start = i;
      while (i + 1 < sorted.length && sorted[i + 1].row === sorted[i].row + 1) i++;
      const values = sorted.slice(start, i + 1).map(record => record[block.key]);
      sheet.getRange(sorted[start].row, block.col, values.length, values[0].length)
        .setValues(values);
      i++;
    }
  }
}

function money_(value) {
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : null;
}
function round_(value) { return Math.round((value + Number.EPSILON) * 100) / 100; }
function safe_(value) {
  if (value === null || value === undefined) return '';
  const text = String(value);
  return /^[=+@]/.test(text) ? "'" + text : text;
}
function date_(value) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T') + '+03:00');
  if (Number.isNaN(date.getTime())) throw new Error('Некорректная дата: ' + value);
  return date;
}
