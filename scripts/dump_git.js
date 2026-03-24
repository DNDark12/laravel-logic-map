const child = require('child_process');
const fs = require('fs');

try {
    let js = child.execSync('git show HEAD:resources/dist/js/logic-map.js', {encoding: 'utf8'});
    fs.writeFileSync('resources/dist/js/logic-map-original.js', js, 'utf8');
    console.log('Successfully wrote ' + js.length + ' bytes to logic-map-original.js');
} catch (e) {
    console.error('Failed to grab js:', e.message);
}
