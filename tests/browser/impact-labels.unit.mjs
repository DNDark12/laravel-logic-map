import assert from 'node:assert/strict';

import { impactNodeLabel } from '../../resources/dist/js/impact-view.js';

const testId = 'test:tests/Feature/Admin/CustomerModuleTest.php::test_customer_keeps_one_default_address_after_update';
const label = impactNodeLabel(testId);

assert.equal(label, 'CustomerModuleTest\ncustomer keeps one default…');
assert.equal(label.split('\n').length, 2);
assert.equal(label.includes('tests/Feature'), false);
assert.equal(label.includes('.php::'), false);

assert.equal(
    impactNodeLabel('method:App\\Services\\OrderService::recalculateTotals'),
    'OrderService · recalculateTotals',
);

console.log('[impact-labels] PASS');
