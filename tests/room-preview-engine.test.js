import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = globalThis;
globalThis.document = { addEventListener() {} };
await import('../public/js/room-preview-engine.js');

const geometry = globalThis.RoomPreviewEngineTest;

test('point-in-polygon accepts floor points and rejects points outside', () => {
    const floor = [[0.1, 0.4], [0.9, 0.4], [0.9, 0.95], [0.1, 0.95]];
    assert.equal(geometry.pointInPolygon(0.5, 0.7, floor), true);
    assert.equal(geometry.pointInPolygon(0.5, 0.2, floor), false);
});

test('polygon area is stable for normalized room geometry', () => {
    assert.equal(geometry.polygonArea([[0, 0], [1, 0], [1, 1], [0, 1]]), 1);
});

test('bounds and overlap checks identify furniture collisions', () => {
    const box = geometry.bounds([[0.2, 0.3], [0.7, 0.3], [0.7, 0.8], [0.2, 0.8]]);
    assert.deepEqual(box, { minX: 0.2, maxX: 0.7, minY: 0.3, maxY: 0.8 });
    assert.equal(geometry.rectanglesOverlap({ left: 0.2, right: 0.5, top: 0.2, bottom: 0.5 }, { left: 0.4, right: 0.7, top: 0.4, bottom: 0.7 }), true);
    assert.equal(geometry.rectanglesOverlap({ left: 0.1, right: 0.2, top: 0.1, bottom: 0.2 }, { left: 0.4, right: 0.7, top: 0.4, bottom: 0.7 }), false);
});
test('blocked polygons contain furniture even when every corner is inside the obstacle', () => {
    const obstacle = [[0.2, 0.2], [0.8, 0.2], [0.8, 0.8], [0.2, 0.8]];
    assert.equal(geometry.polygonIntersectsRect(obstacle, { left: 0.4, right: 0.6, top: 0.4, bottom: 0.6 }), true);
    assert.equal(geometry.polygonIntersectsRect(obstacle, { left: 0.85, right: 0.95, top: 0.4, bottom: 0.6 }), false);
});
test('scale clues use a reliability-weighted median and ignore a low-confidence outlier', () => {
    assert.equal(geometry.weightedMedian([
        { scale: 10, reliability: 0.8 },
        { scale: 11, reliability: 0.2 },
        { scale: 80, reliability: 0.01 },
    ]), 10);
});
test('footprints crossing a concave floor edge are rejected even when some corners are inside', () => {
    const concaveFloor = [[0.1,0.4],[0.9,0.4],[0.9,0.9],[0.6,0.9],[0.6,0.65],[0.4,0.65],[0.4,0.9],[0.1,0.9]];
    const crossingFootprint = [[0.35,0.7],[0.65,0.7],[0.65,0.82],[0.35,0.82]];
    const safeFootprint = [[0.15,0.7],[0.35,0.7],[0.35,0.82],[0.15,0.82]];
    assert.equal(geometry.polygonInsidePolygon(crossingFootprint, concaveFloor), false);
    assert.equal(geometry.polygonInsidePolygon(safeFootprint, concaveFloor), true);
});
function photoEngine(roomWidth = 0, imageWidth = 1200, imageHeight = 800) {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    Object.assign(engine, { frame: { dataset: { roomWidth } }, room: { naturalWidth: imageWidth, naturalHeight: imageHeight }, item: { naturalWidth: 600, naturalHeight: 300 }, floor: [[0.17,0.58],[0.83,0.58],[0.99,0.99],[0.01,0.99]], horizon: 0.4, analysis: {}, vision: {}, blocked: [], existing: [], openings: [], warnings: [], placementMode: 'floor' });
    return engine;
}

test('photo-only sizing ignores customer room measurements', () => {
    const dims = {width:225, depth:90, height:92};
    const a = photoEngine(2).autoFit(dims);
    const b = photoEngine(50).autoFit(dims);
    assert.deepEqual(a, b);
    assert.ok(a.width > 0.2, 'sala set must not be a tiny icon');
    assert.ok(a.y > 0.7, 'furniture should be grounded in the visible floor');
});

test('furniture keeps its pixel aspect ratio in landscape and portrait rooms', () => {
    for (const [w,h] of [[1200,800],[800,1200]]) {
        const engine = photoEngine(0,w,h);
        const p = engine.autoFit({width:225,depth:90,height:92});
        assert.ok(p);
        assert.ok(Math.abs((p.width*w)/(p.height*h)-2) < 1e-8);
        assert.ok(p.x-p.width/2 >= 0.02 && p.x+p.width/2 <= 0.98);
        assert.ok(p.y-p.height >= 0.02 && p.y <= 0.98);
    }
});
