# Browser Smoke Tests

## Panel Detail State Regression

This smoke test verifies the `hide -> restore -> clear` flow for the detail panel across desktop, tablet, and mobile breakpoints.

Run it from the package root:

```bash
npx -y -p playwright node tests/browser/panel-detail-state.smoke.mjs
```

Optional environment overrides:

```bash
LOGIC_MAP_BASE_URL="http://127.0.0.1:8000/logic-map" \
LOGIC_MAP_ROUTE_ID="route:logic-map/overview" \
npx -y -p playwright node tests/browser/panel-detail-state.smoke.mjs
```
