/*
 * Deterministic automatic 2D room placement and canvas renderer.
 * All formulas below are image-space estimates; they are not construction measurements.
 */
(() => {
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const readJson = (element, fallback = {}) => {
        try { return JSON.parse(element?.textContent || JSON.stringify(fallback)); } catch (_) { return fallback; }
    };
    const normalizePoint = (point) => {
        if (!Array.isArray(point) || point.length < 2) return null;
        let x = Number(point[0]);
        let y = Number(point[1]);
        if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
        if (Math.max(Math.abs(x), Math.abs(y)) > 1.001) { x /= 100; y /= 100; }
        return x >= 0 && x <= 1 && y >= 0 && y <= 1 ? [x, y] : null;
    };
    const polygon = (value) => Array.isArray(value) ? value.map(normalizePoint).filter(Boolean) : [];
    const pointInPolygon = (x, y, points) => {
        if (points.length < 3) return false;
        let inside = false;
        for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
            const [xi, yi] = points[i];
            const [xj, yj] = points[j];
            const crosses = ((yi > y) !== (yj > y)) && x < ((xj - xi) * (y - yi) / ((yj - yi) || 1e-8)) + xi;
            if (crosses) inside = !inside;
        }
        return inside;
    };
    const bounds = (points) => points.length ? {
        minX: Math.min(...points.map(([x]) => x)), maxX: Math.max(...points.map(([x]) => x)),
        minY: Math.min(...points.map(([, y]) => y)), maxY: Math.max(...points.map(([, y]) => y)),
    } : null;
    const polygonArea = (points) => Math.abs(points.reduce((sum, [x, y], i) => {
        const [nx, ny] = points[(i + 1) % points.length];
        return sum + (x * ny) - (nx * y);
    }, 0) / 2);
    const weightedMedian = (items) => {
        const sorted = [...items].filter((item) => Number(item.reliability) > 0).sort((a, b) => a.scale - b.scale);
        const total = sorted.reduce((sum, item) => sum + Number(item.reliability), 0);
        let accumulated = 0;
        return sorted.find((item) => (accumulated += Number(item.reliability)) >= total / 2)?.scale ?? null;
    };
    const polygonInsidePolygon = (inner, outer) => {
        if (inner.length < 3 || outer.length < 3) return false;
        for (let i = 0; i < inner.length; i += 1) {
            const start = inner[i];
            const end = inner[(i + 1) % inner.length];
            // Sampling each footprint edge catches concave floor notches between its corners.
            for (let step = 0; step <= 8; step += 1) {
                const t = step / 8;
                if (!pointInPolygon(start[0] + (end[0] - start[0]) * t, start[1] + (end[1] - start[1]) * t, outer)) return false;
            }
        }
        return true;
    };
    const rectanglesOverlap = (a, b) => a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
    const polygonIntersectsRect = (points, rect) => {
        const inRect = ([x, y]) => x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
        if (points.some(inRect)) return true;
        const corners = [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]];
        if (corners.some(([x, y]) => pointInPolygon(x, y, points))) return true;
        const orient = (a, b, c) => (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
        const segmentsCross = (a, b, c, d) => orient(a, b, c) * orient(a, b, d) <= 0 && orient(c, d, a) * orient(c, d, b) <= 0;
        for (let i = 0; i < points.length; i += 1) {
            const a = points[i], b = points[(i + 1) % points.length];
            for (let j = 0; j < corners.length; j += 1) {
                if (segmentsCross(a, b, corners[j], corners[(j + 1) % corners.length])) return true;
            }
        }
        return false;
    };

    class RoomPreviewEngine {
        constructor(frame) {
            this.frame = frame;
            this.canvas = frame.querySelector('[data-room-preview-canvas]');
            this.context = this.canvas?.getContext('2d');
            this.room = frame.querySelector('[data-preview-room]');
            this.item = frame.querySelector('[data-preview-product]');
            this.analysis = readJson(frame.querySelector('[data-preview-analysis]'));
            this.vision = this.analysis.vision_geometry || null;
            this.status = document.querySelector('[data-placement-status]');
            this.dimensions = document.querySelector('[data-placement-dimensions]');
            this.guidance = document.querySelector('[data-placement-guidance]');
            this.loading = frame.querySelector('[data-preview-loading]');
            this.download = frame.closest('.comparison-panel')?.querySelector('[data-preview-download]');
            this.detections = frame.closest('.comparison-panel')?.querySelector('[data-preview-detections]');
            this.warnings = [];
            const confidenceValue = this.vision?.confidence ?? this.analysis.confidence ?? this.analysis.scene_confidence ?? this.analysis.placement_confidence ?? 0.35;
            this.confidence = typeof confidenceValue === 'string' ? ({ high: 0.82, medium: 0.62, low: 0.38 }[confidenceValue.toLowerCase()] || 0.35) : clamp(Number(confidenceValue) || 0.35, 0, 1);
            this.initialConfidence = this.confidence;
            this.runId = 0;
            this.result = null;
            this.floor = polygon(this.vision?.floor_polygon || this.analysis.floor_polygon || []);
            this.floorDetected = this.floor.length >= 3;
            this.horizon = clamp(Number(this.vision?.horizon_y ?? this.analysis.horizon_y_percent ?? 40) / (Number(this.vision?.horizon_y ?? this.analysis.horizon_y_percent ?? 40) > 1 ? 100 : 1), 0.05, 0.9);
            if (!this.floorDetected) {
                // Conservative trapezoid for local 2D planning when AI floor segmentation is unavailable.
                const floorTop = clamp(this.horizon + 0.18, 0.52, 0.72);
                this.floor = [[0.17, floorTop], [0.83, floorTop], [0.99, 0.99], [0.01, 0.99]];
            }
            const visionOpeningRegions = (this.vision?.openings || []).map((opening) => ({ polygon: this.bboxPolygon(opening.bbox), type: opening.type }));
            // Prefer the validated room-facing legacy door quad when both schemas describe the same opening.
            this.openings = [...(this.analysis.openings || []), ...(this.vision?.openings || [])];
            this.blocked = [...(this.vision?.blocked_zones || []), ...(this.analysis.blocked_zones || []), ...(this.analysis.obstacles || []), ...(this.analysis.openings || []), ...visionOpeningRegions]
                .map((region) => polygon(region.polygon || region.bbox)).filter((shape) => shape.length >= 3);
            this.existing = [...(this.vision?.existing_furniture || []), ...(this.analysis.existing_furniture || [])].map((item) => this.rectFromBbox(item.bbox)).filter(Boolean);
            this.itemType = `${frame.dataset.productType || ''} ${frame.dataset.productCategory || ''}`.toLowerCase();
            this.placementMode = /door|doorway|entry/.test(this.itemType) ? 'door' : /cabinet|shelf|wardrobe|wall/.test(this.itemType) ? 'wall' : 'floor';
            this.setupComparisonSlider();
        }


        setupComparisonSlider() {
            if (!this.frame.classList.contains('room-comparison')) return;

            let dragging = false;
            const update = (clientX) => {
                const bounds = this.frame.getBoundingClientRect();
                if (!bounds.width) return;
                const value = clamp(((clientX - bounds.left) / bounds.width) * 100, 2, 98);
                const rounded = Math.round(value);
                this.frame.style.setProperty('--split', `${value}%`);
                this.frame.setAttribute('aria-valuenow', String(rounded));
                this.frame.setAttribute('aria-valuetext', `${rounded}% before, ${100 - rounded}% after`);
            };

            this.frame.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) return;
                dragging = true;
                this.frame.setPointerCapture(event.pointerId);
                update(event.clientX);
                event.preventDefault();
            });
            this.frame.addEventListener('pointermove', (event) => {
                if (dragging) update(event.clientX);
            });
            const stopDragging = (event) => {
                dragging = false;
                if (this.frame.hasPointerCapture?.(event.pointerId)) this.frame.releasePointerCapture(event.pointerId);
            };
            this.frame.addEventListener('pointerup', stopDragging);
            this.frame.addEventListener('pointercancel', stopDragging);
            this.frame.addEventListener('keydown', (event) => {
                const current = Number(this.frame.getAttribute('aria-valuenow')) || 50;
                let next = current;
                if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') next -= 4;
                else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') next += 4;
                else if (event.key === 'Home') next = 2;
                else if (event.key === 'End') next = 98;
                else return;
                event.preventDefault();
                update(this.frame.getBoundingClientRect().left + (clamp(next, 2, 98) / 100) * this.frame.getBoundingClientRect().width);
            });
        }

        bboxPolygon(box) {

            const rect = this.rectFromBbox(box);

            return rect ? [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]] : [];

        }


        rectFromBbox(box) {
            if (!Array.isArray(box) || box.length !== 4) return null;
            let [x1, y1, x2, y2] = box.map(Number);
            if (![x1, y1, x2, y2].every(Number.isFinite)) return null;
            if (Math.max(Math.abs(x1), Math.abs(y1), Math.abs(x2), Math.abs(y2)) > 1.001) [x1, y1, x2, y2] = [x1 / 100, y1 / 100, x2 / 100, y2 / 100];
            return { left: Math.min(x1, x2), right: Math.max(x1, x2), top: Math.min(y1, y2), bottom: Math.max(y1, y2) };
        }

        async selectProduct(button) {
            this.frame.dataset.productId = button.dataset.productId || '';
            this.frame.dataset.productName = button.dataset.productName || '';
            this.frame.dataset.productWidth = button.dataset.productWidth || '';
            this.frame.dataset.productDepth = button.dataset.productDepth || '';
            this.frame.dataset.productHeight = button.dataset.productHeight || '';
            this.frame.dataset.productDimensionsEstimated = button.dataset.productDimensionsEstimated || 'false';
            this.frame.dataset.productType = button.dataset.productType || '';
            this.frame.dataset.productCategory = button.dataset.productCategory || '';
            this.frame.dataset.verifyUrl = button.dataset.verifyUrl || '';
            this.item.src = button.dataset.productImage || '';
            this.result = null;
            this.frame.dataset.finalPlacement = '';
            this.itemType = `${this.frame.dataset.productType} ${this.frame.dataset.productCategory}`.toLowerCase();
            this.placementMode = /door|doorway|entry/.test(this.itemType) ? 'door' : /cabinet|shelf|wardrobe|wall/.test(this.itemType) ? 'wall' : 'floor';
            this.warnings = [];
            this.confidence = this.initialConfidence;
            document.querySelectorAll('[data-inline-preview-name]').forEach((node) => { node.textContent = button.dataset.productName || 'Selected furniture'; });
            const image = document.querySelector('[data-inline-preview-card-image]');
            if (image) { image.src = button.dataset.productImage || ''; image.alt = button.dataset.productName || ''; }
            const score = document.querySelector('[data-inline-preview-score]');
            if (score) score.textContent = `${button.dataset.productScore || '0'}% match`;
            const price = document.querySelector('[data-inline-preview-price]');
            if (price) price.textContent = button.dataset.productPrice || '0.00';
            const link = document.querySelector('[data-inline-preview-link]');
            if (link) link.href = button.dataset.productUrl || '#';
            document.querySelectorAll('[data-recommendation-preview]').forEach((control) => {
                const selected = control === button;
                control.setAttribute('aria-pressed', String(selected));
                control.textContent = selected ? 'Selected' : 'Preview in room';
            });
            const runId = ++this.runId;
            await this.start(runId);
        }

        async start(runId = ++this.runId) {
            this.warnings = [];
            this.confidence = this.initialConfidence;
            if (!this.canvas || !this.context || !this.room || !this.item) {
                this.setStatus('Dimensions not available for this item.', '');
                return;
            }
            this.setProgress('Analyzing room');
            try {
                await Promise.all([this.room.decode(), this.item.decode()]);
                if (runId !== this.runId) return;
            } catch (_) {
                this.setProgress('');
                this.setStatus('Room or furniture image could not be loaded. Upload another photo to try again.', '');
                return;
            }
            const dims = this.dimensionsFromCatalog();
            if (!dims) {
                this.drawRoomOnly();
                this.setProgress('');
                this.setStatus('Dimensions not available for this item.', '');
                if (this.dimensions) this.dimensions.textContent = 'This furniture item needs width, depth, and height in the catalog before it can be previewed.';
                return;
            }
            this.dimensions && (this.dimensions.textContent = `${dims.width} × ${dims.depth} × ${dims.height} cm ${dims.estimated ? 'standard planning estimate; confirm with shop' : 'catalog dimensions'}`);
            if (this.vision?.image_quality?.usable === false && !(this.placementMode === 'door' && this.openingForDoor() && (this.vision.image_quality.issues || []).includes('no_floor_visible') && (this.vision.image_quality.issues || []).every((issue) => issue === 'no_floor_visible'))) {
                this.drawRoomOnly();
                const issue = (this.vision.image_quality.issues || [])[0] || 'no_floor_visible';
                const tips = { blurry: 'The room photo is blurry. Retake it with the camera held steady.', too_dark: 'The room photo is too dark. Turn on the lights and retake it.', too_close: 'The photo is too close. Step back and include the floor and walls.', no_floor_visible: 'The floor is not visible. Take the photo from the doorway so the floor is visible.', extreme_wide_angle: 'The wide-angle view distorts room proportions. Retake from farther back using the normal camera lens.' };
                this.setStatus('Room photo needs a clearer view', 'low');
                if (this.guidance) this.guidance.textContent = tips[issue] || tips.no_floor_visible;
                this.setProgress('');
                return;
            }
            this.setProgress('Fitting furniture');
            if (this.placementMode !== 'door' && (!this.floorDetected || !(this.vision?.scale_clues || this.analysis.scale_clues || []).length)) {
                this.confidence = Math.min(this.confidence, 0.42);
                this.warnings.push(!this.floorDetected ? 'No clear floor boundary was detected; an estimated floor area is being used for a rough placement.' : 'No reliable real-world scale clue was detected; furniture size is a rough estimate.');
            }
            const placement = this.autoFit(dims);
            if (!placement) {
                this.drawRoomOnly();
                this.setProgress('');
                this.setStatus('Doorway not detected', 'low');
                if (this.guidance) this.guidance.textContent = 'Re-analyze with the full doorway visible and AI room analysis enabled. FurniCraft will only fit a door to a detected doorway.';
                return;
            }
            this.result = placement;
            this.draw(placement, dims);
            this.report(placement);
            let aiImageShown = false;
            if (this.frame.dataset.consented === 'true' && this.frame.dataset.previewV2Url) {
                aiImageShown = await this.generateAiPreview(runId);
                if (runId !== this.runId) return;
            }
            if (!aiImageShown && this.frame.dataset.consented === 'true' && this.frame.dataset.verifyUrl) {
                this.setProgress('Checking the result');
                await this.verifyLoop(dims, runId);
                if (runId !== this.runId) return;
            } else if (!aiImageShown) {
                this.confidence = Math.min(this.confidence, 0.45);
                this.report(this.result);
            }
            this.setProgress('');
            this.frame.dataset.finalPlacement = JSON.stringify(this.result);
            if (this.download) this.download.onclick = () => this.downloadPng();
        }

        dimensionsFromCatalog() {
            const catalog = {
                width: Number(this.frame.dataset.productWidth),
                depth: Number(this.frame.dataset.productDepth),
                height: Number(this.frame.dataset.productHeight),
            };
            const type = this.itemType || `${this.frame.dataset.productType || ''} ${this.frame.dataset.productCategory || ''}`.toLowerCase();
            const estimate = /door|doorway|entry/.test(type) ? [90, 4, 210]
                : /bed|bunk|double.deck/.test(type) ? [150, 205, 110]
                : /cabinet|wardrobe|dresser|shelf|storage/.test(type) ? [100, 45, 180]
                : /sala|sofa|couch|sectional|living.room/.test(type) ? [225, 90, 92]
                : /dining/.test(type) ? [180, 90, 76]
                : /chair|stool|bench|seating/.test(type) ? [60, 65, 95]
                : /table|desk/.test(type) ? [105, 60, 75]
                : [100, 60, 90];
            const estimated = this.frame.dataset.productDimensionsEstimated === 'true' || !(catalog.width > 0 && catalog.depth > 0 && catalog.height > 0);
            return {
                width: catalog.width > 0 ? catalog.width : estimate[0],
                depth: catalog.depth > 0 ? catalog.depth : estimate[1],
                height: catalog.height > 0 ? catalog.height : estimate[2],
                estimated,
            };
        }

        setProgress(step) {
            if (!this.loading) return;
            this.loading.hidden = !step;
            if (step) this.loading.textContent = `Uploading · Analyzing room · Fitting furniture · Checking the result — ${step}`;
        }

        setStatus(text, level = '') {
            if (this.status) this.status.textContent = text;
            this.frame.dataset.resultLevel = level;
        }

        floorWidthAt(y) {
            if (this.floor.length < 3) return 0;
            const intersections = [];
            for (let index = 0; index < this.floor.length; index += 1) {
                const [x1, y1] = this.floor[index];
                const [x2, y2] = this.floor[(index + 1) % this.floor.length];
                if (Math.abs(y2 - y1) < 1e-6) {
                    if (Math.abs(y - y1) < 1e-4) intersections.push(x1, x2);
                    continue;
                }
                const low = Math.min(y1, y2), high = Math.max(y1, y2);
                if (y < low || y > high) continue;
                const t = (y - y1) / (y2 - y1);
                intersections.push(x1 + (x2 - x1) * t);
            }
            if (intersections.length < 2) return 0;
            return Math.max(...intersections) - Math.min(...intersections);
        }

        estimateScale(y, opening = null) {
            const imageHeight = this.room.naturalHeight;
            if (opening) {
                const box = this.rectFromBbox(opening.bbox);
                const polygonBounds = bounds(polygon(opening.polygon));
                const pixelHeight = box ? (box.bottom - box.top) * imageHeight : polygonBounds ? (polygonBounds.maxY - polygonBounds.minY) * imageHeight : 0;
                const typicalHeight = Number(opening.estimated_height_cm || 210);
                if (pixelHeight > 0 && typicalHeight > 0) return pixelHeight / typicalHeight;
            }
            const clueScales = (this.vision?.scale_clues || this.analysis.scale_clues || []).map((clue) => {
                const clueType = String(clue.object || '').toLowerCase();
                const priority = /door/.test(clueType) ? 1 : /ceiling/.test(clueType) ? 0.85 : /window/.test(clueType) ? 0.7 : 0.55;
                const reliability = clamp(Number(clue.reliability || 0) * priority, 0, 1);
                const pixels = Number(clue.pixel_height || 0);
                const realCm = Number(clue.typical_real_cm || 0);
                const baseY = Number(clue.base_y || 0);
                const normalizedBase = baseY > 1 ? baseY / 100 : baseY;
                const deltaPixels = (normalizedBase - this.horizon) * imageHeight;
                const k = pixels > 0 && realCm > 0 && deltaPixels > 0 ? pixels / (realCm * deltaPixels) : 0;
                const scale = k * Math.max(0, (y - this.horizon) * imageHeight);
                return scale > 0 && reliability > 0 ? { scale, reliability } : null;
            }).filter(Boolean);
            if (clueScales.length) {
                const median = weightedMedian(clueScales);
                const reliable = clueScales.filter((entry) => Math.abs(entry.scale - median) / median <= 0.25);
                const total = reliable.reduce((sum, entry) => sum + entry.reliability, 0);
                if (total > 0) return reliable.reduce((sum, entry) => sum + entry.scale * entry.reliability, 0) / total;
            }
            // Use photo geometry rather than customer-entered room measurements.
            const floorSpan = this.floorWidthAt(y) || 0.72;
            const perspective = clamp((y - this.horizon) / (1 - this.horizon), 0.45, 1);
            return this.room.naturalWidth * floorSpan / 400 * perspective;
        }

        openingRect(opening) {
            const box = opening.bbox ? this.rectFromBbox(opening.bbox) : null;
            if (box) return box;
            const shape = polygon(opening.polygon || []);
            const value = bounds(shape);
            return value ? { left: value.minX, right: value.maxX, top: value.minY, bottom: value.maxY } : null;
        }
        openingForDoor() {
            return this.openings.find((item) => {
                const label = `${item.type || ''} ${item.label || ''}`.toLowerCase();
                return /door|doorway|entry|entrance|archway|closet|passage/.test(label) && !/window|skylight|transom/.test(label);
            }) || null;
        }

        autoFit(dims) {
            const naturalAspect = this.item.naturalWidth / Math.max(1, this.item.naturalHeight);
            if (this.placementMode === 'door') {
                const opening = this.openingForDoor();
                if (opening) {
                    const quad = polygon(opening.door_fit_quad_percent || opening.polygon);
                    const rect = this.rectFromBbox(opening.bbox);
                    if (quad.length === 4 || rect) {
                        this.confidence = Math.max(this.confidence, 0.55);
                        return { mode: 'door', quad: quad.length === 4 ? quad : [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]], x: rect ? (rect.left + rect.right) / 2 : 0.5, y: rect?.bottom || 0.94, width: rect ? rect.right - rect.left : 0.18, scale: 1, score: 0.8 };
                    }
                }
                this.warnings.push('No reliable doorway was found.');
                return null;
            }

            let best = null;
            const floorBox = bounds(this.floor);
            if (this.placementMode === 'wall') best = this.fitWall(dims, naturalAspect);
            if (!best && floorBox && this.floor.length >= 3) {
                for (let factor = 1; factor >= 0.6 && !best; factor = Math.round((factor - 0.05) * 100) / 100) {
                    for (let y = Math.max(floorBox.minY + 0.04, this.horizon + 0.04); y <= floorBox.maxY; y += 0.035) {
                        for (let x = floorBox.minX + 0.04; x <= floorBox.maxX - 0.04; x += 0.04) {
                            const candidate = this.candidateAt(x, y, dims, naturalAspect, factor);
                            if (!candidate) continue;
                            if (!best || candidate.score > best.score) best = candidate;
                        }
                    }
                }
            }
            if (best) {
                if (best.scale < 0.995) this.warnings.push('Furniture was scaled down to fit the visible floor area.');
                return best;
            }
            this.warnings.push('This item may be too large for this space.');
            const safeX = floorBox ? (floorBox.minX + floorBox.maxX) / 2 : Number(this.frame.dataset.initialX || 50) / 100;
            const safeY = floorBox ? clamp(floorBox.minY + (floorBox.maxY - floorBox.minY) * 0.7, 0.35, 0.97) : 0.86;
            return this.candidateAt(clamp(safeX, 0.08, 0.92), safeY, dims, naturalAspect, 0.6, true);
        }

        candidateAt(x, y, dims, naturalAspect, scaleFactor, relaxed = false) {
            if (!relaxed && !pointInPolygon(x, y, this.floor)) return null;
            const pxPerCm = this.estimateScale(y);
            const expectedWidth = dims.width * pxPerCm * scaleFactor / this.room.naturalWidth;
            let width = expectedWidth;
            let height = width * (this.room.naturalWidth / this.room.naturalHeight) / naturalAspect;
            let fittedScale = scaleFactor;
            if (relaxed && (width > 0.9 || height > 0.88)) {
                const fit = Math.min(0.9 / Math.max(width, 1e-6), 0.88 / Math.max(height, 1e-6), 1);
                width *= fit;
                height *= fit;
                fittedScale *= fit;
            }
            const depth = clamp(dims.depth * pxPerCm * fittedScale * 0.35 / this.room.naturalHeight, 0.008, 0.18);
            const rect = { left: x - width / 2, right: x + width / 2, top: y - height, bottom: y };
            if (!relaxed && (rect.left < 0.02 || rect.right > 0.98 || rect.top < 0.02 || rect.bottom > 0.98)) return null;
            const foot = [[rect.left, y], [x, y], [rect.right, y], [rect.left, y - depth], [x, y - depth], [rect.right, y - depth]];
            if (!relaxed && !polygonInsidePolygon(foot, this.floor)) return null;
            if (!relaxed && this.blocked.some((shape) => polygonIntersectsRect(shape, rect))) return null;
            if (!relaxed && this.existing.some((box) => rectanglesOverlap(rect, box))) return null;
            const floor = bounds(this.floor);
            const nearBackWall = floor ? 1 - clamp(Math.abs(y - (floor.minY + (floor.maxY - floor.minY) * 0.48)) / 0.5, 0, 1) : 0.35;
            const centered = 1 - Math.abs(x - 0.5) * 1.2;
            const openArea = 1 - this.existing.length * 0.08;
            const doors = this.openings.filter((opening) => ['door', 'archway'].includes(String(opening.type || '').toLowerCase()) || /door|entry|entrance/i.test(String(opening.label || '')));
            const nearestDoor = doors.reduce((distance, opening) => {
                const openingBox = this.openingRect(opening);
                if (!openingBox) return distance;
                const pixels = Math.hypot((x - (openingBox.left + openingBox.right) / 2) * this.room.naturalWidth, (y - openingBox.bottom) * this.room.naturalHeight);
                return Math.min(distance, pixels);
            }, Infinity);
            // Preserve roughly 60–90 cm of door-side walking space when image scale is usable.
            const clearancePixels = this.estimateScale(y) * 75;
            const doorWalkway = Number.isFinite(nearestDoor) ? clamp(nearestDoor / Math.max(1, clearancePixels), 0, 1) : 0.75;
            return { mode: 'floor', x, y, width, height, depth, scale: fittedScale, score: 0.36 * nearBackWall + 0.23 * centered + 0.16 * openArea + 0.25 * doorWalkway };
        }

        fitWall(dims, aspect) {
            const walls = [...(this.vision?.walls || []), ...(this.analysis.wall_regions || [])].map((wall) => polygon(wall.polygon)).filter((shape) => shape.length >= 3);
            const wall = walls.sort((a, b) => polygonArea(b) - polygonArea(a))[0];
            if (!wall) return null;
            const box = bounds(wall);
            const x = (box.minX + box.maxX) / 2;
            const y = (box.minY + box.maxY) / 2;
            const pxPerCm = this.estimateScale(clamp(y, 0.1, 0.9));
            const width = Math.min(dims.width * pxPerCm / this.room.naturalWidth, (box.maxX - box.minX) * 0.7);
            const height = width * (this.room.naturalWidth / this.room.naturalHeight) / aspect;
            const rect = { left: x - width / 2, right: x + width / 2, top: y - height / 2, bottom: y + height / 2 };
            const corners = [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]];
            if (!corners.every(([cx, cy]) => pointInPolygon(cx, cy, wall))) return null;
            if (this.blocked.some((shape) => polygonIntersectsRect(shape, rect))) return null;
            return { mode: 'wall', x, y: rect.bottom, width, height, scale: 1, score: 0.72 };
        }

        drawRoomOnly() {
            const size = this.canvasSize();
            if (size) this.context.drawImage(this.room, 0, 0, size.width, size.height);
        }

        canvasSize() {
            if (!this.room?.naturalWidth || !this.room?.naturalHeight) return null;
            const ratio = Math.min(1, 1600 / Math.max(this.room.naturalWidth, this.room.naturalHeight));
            const width = Math.max(1, Math.round(this.room.naturalWidth * ratio));
            const height = Math.max(1, Math.round(this.room.naturalHeight * ratio));
            this.canvas.width = width;
            this.canvas.height = height;
            this.canvas.style.aspectRatio = `${width} / ${height}`;
            return { width, height };
        }

        draw(placement, dims) {
            const size = this.canvasSize();
            if (!size) return;
            const ctx = this.context;
            const { width, height } = size;
            ctx.clearRect(0, 0, width, height);
            ctx.drawImage(this.room, 0, 0, width, height);
            if (!this.dimensionsFromCatalog()) return;
            ctx.save();
            const lighting = this.vision?.lighting || (typeof this.analysis.lighting === 'object' ? this.analysis.lighting : {});
            const brightness = clamp(Number(lighting.brightness ?? 0.55), 0, 1);
            const x = placement.x * width;
            const y = placement.y * height;
            const itemWidth = placement.width * width;
            const itemHeight = placement.height * height;
            ctx.globalAlpha = 0.12 + brightness * 0.13;
            ctx.fillStyle = '#171a18';
            ctx.filter = 'blur(12px)';
            ctx.beginPath();
            ctx.ellipse(x, y - Math.max(2, itemHeight * 0.015), Math.max(3, itemWidth * 0.34), Math.max(2, itemHeight * 0.035), 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
            if (placement.mode === 'door' && placement.quad?.length === 4) {
                this.drawQuad(ctx, this.item, placement.quad.map(([qx, qy]) => [qx * width, qy * height]));
            } else {
                const lightFrom = String(lighting.light_from || 'unknown');
                const flip = typeof placement.flip === 'boolean' ? (placement.flip ? -1 : 1) : lightFrom === 'right' ? -1 : 1;
                ctx.save();
                ctx.globalAlpha = 0.94;
                ctx.filter = `brightness(${clamp(0.92 + brightness * 0.13, 0.85, 1.08)}) saturate(${clamp(0.88 + Number(lighting.warmth || 0.45) * 0.18, 0.8, 1.1)})`;
                ctx.shadowColor = 'rgba(20, 24, 21, .24)';
                ctx.shadowBlur = Math.max(2, itemWidth * 0.025);
                ctx.shadowOffsetY = Math.max(1, itemHeight * 0.012);
                ctx.translate(x, y);
                ctx.scale(flip, 1);
                ctx.drawImage(this.item, -itemWidth / 2, -itemHeight, itemWidth, itemHeight);
                ctx.restore();
            }
        }

        drawQuad(ctx, image, quad) {
            const [tl, tr, br, bl] = quad;
            const project = (u, v) => [
                (1-u)*(1-v)*tl[0] + u*(1-v)*tr[0] + u*v*br[0] + (1-u)*v*bl[0],
                (1-u)*(1-v)*tl[1] + u*(1-v)*tr[1] + u*v*br[1] + (1-u)*v*bl[1],
            ];
            const triangle = (source, target) => {
                const [[sx0,sy0],[sx1,sy1],[sx2,sy2]] = source;
                const [[dx0,dy0],[dx1,dy1],[dx2,dy2]] = target;
                const den = sx0*(sy1-sy2)+sx1*(sy2-sy0)+sx2*(sy0-sy1);
                if (Math.abs(den) < 1e-6) return;
                const a=(dx0*(sy1-sy2)+dx1*(sy2-sy0)+dx2*(sy0-sy1))/den;
                const b=(dy0*(sy1-sy2)+dy1*(sy2-sy0)+dy2*(sy0-sy1))/den;
                const c=(dx0*(sx2-sx1)+dx1*(sx0-sx2)+dx2*(sx1-sx0))/den;
                const d=(dy0*(sx2-sx1)+dy1*(sx0-sx2)+dy2*(sx1-sx0))/den;
                const e=(dx0*(sx1*sy2-sx2*sy1)+dx1*(sx2*sy0-sx0*sy2)+dx2*(sx0*sy1-sx1*sy0))/den;
                const f=(dy0*(sx1*sy2-sx2*sy1)+dy1*(sx2*sy0-sx0*sy2)+dy2*(sx0*sy1-sx1*sy0))/den;
                ctx.save(); ctx.beginPath(); ctx.moveTo(dx0,dy0); ctx.lineTo(dx1,dy1); ctx.lineTo(dx2,dy2); ctx.closePath(); ctx.clip();
                ctx.transform(a,b,c,d,e,f); ctx.drawImage(image,0,0); ctx.restore();
            };
            const cols = 10, rows = 18;
            for (let r=0;r<rows;r++) for (let c=0;c<cols;c++) {
                const u0=c/cols,u1=(c+1)/cols,v0=r/rows,v1=(r+1)/rows;
                const src=[[u0*image.naturalWidth,v0*image.naturalHeight],[u1*image.naturalWidth,v0*image.naturalHeight],[u1*image.naturalWidth,v1*image.naturalHeight],[u0*image.naturalWidth,v1*image.naturalHeight]];
                const dst=[project(u0,v0),project(u1,v0),project(u1,v1),project(u0,v1)];
                triangle([src[0],src[1],src[2]],[dst[0],dst[1],dst[2]]);
                triangle([src[0],src[2],src[3]],[dst[0],dst[2],dst[3]]);
            }
        }

        async generateAiPreview(runId) {
            const endpoint = this.frame.dataset.previewV2Url;
            const productId = Number(this.frame.dataset.productId || 0);
            if (!endpoint || !productId) return false;

            const retry = this.retryAiPreview === true;
            this.retryAiPreview = false;
            this.setProgress('Generating an AI-fitted room image');
            try {
                const response = await fetch(endpoint, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.frame.dataset.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ furniture_id: productId, retry }),
                });
                if (!response.ok) return false;
                let payload = await response.json();
                const deadline = Date.now() + 500000;
                while (['queued', 'processing', 'retrying'].includes(payload.status) && Date.now() < deadline) {
                    if (runId !== this.runId) return false;
                    this.setProgress(payload.status === 'queued' ? 'Queuing AI room preview' : 'Fitting furniture into the room image');
                    await new Promise((resolve) => window.setTimeout(resolve, 2500));
                    const statusResponse = await fetch(payload.status_url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    if (!statusResponse.ok) return false;
                    payload = await statusResponse.json();
                }
                if (runId !== this.runId || !['completed', 'fallback'].includes(payload.status) || !payload.preview_url) return false;

                const generated = new Image();
                generated.src = payload.preview_url;
                await generated.decode();
                if (runId !== this.runId) return false;
                const size = this.canvasSize();
                if (!size) return false;
                this.context.clearRect(0, 0, size.width, size.height);
                this.context.drawImage(generated, 0, 0, size.width, size.height);
                this.report(this.result);
                const verified = payload.status === 'completed';
                this.setStatus(verified ? 'AI visual preview ready' : 'AI fitting unavailable — local estimate shown', 'low');
                if (this.guidance) this.guidance.textContent = verified
                    ? 'The preview was generated from your room photo and the selected catalog item. Apparent size is an AI visual estimate.'
                    : (payload.message || 'AI fitting was unavailable, so a local 2D estimate is shown. Treat the apparent size and placement as an estimate.');
                if (this.detections) {
                    const badge = verified ? 'AI visual preview' : 'Local estimate';
                    this.detections.innerHTML = `<span class="preview-badge is-rough">${badge}</span>`;
                }
                return true;
            } catch (_) {
                return false;
            }
        }
        async verifyLoop(dims, runId = this.runId) {
            let best = { ...this.result };
            let bestScore = -1;
            for (let attempt = 0; attempt < 3; attempt += 1) {
                if (runId !== this.runId) return;
                this.setProgress(`Checking the result (${attempt + 1}/3)`);
                try {
                    const preview = this.canvas.toDataURL('image/jpeg', 0.78).split(',')[1];
                    const response = await fetch(this.frame.dataset.verifyUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.frame.dataset.csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ preview, placement: this.result, product_id: this.frame.dataset.productId }),
                    });
                    if (!response.ok) throw new Error('verification unavailable');
                    const check = await response.json();
                    if (runId !== this.runId) return;
                    if (check.valid !== true) break;
                    const score = clamp(Number(check.realism_score || 0), 0, 1);
                    if (score > bestScore) { best = { ...this.result }; bestScore = score; }
                    if (check.verdict === 'good' && score >= 0.75) break;
                    const correction = check.corrections || {};
                    const correctedScale = clamp(this.result.scale * clamp(Number(correction.scale_factor || 1), 0.6, 1.4), 0.6, 1.3);
                    const candidate = { ...this.result, x: clamp(this.result.x + Number(correction.dx || 0), 0.02, 0.98), y: clamp(this.result.y + Number(correction.dy || 0), 0.02, 0.98), scale: correctedScale, width: this.result.width * correctedScale / Math.max(0.001, this.result.scale), height: this.result.height * correctedScale / Math.max(0.001, this.result.scale), depth: this.result.depth * correctedScale / Math.max(0.001, this.result.scale) };
                    if (typeof correction.flip === 'boolean') candidate.flip = correction.flip;
                    if (candidate.mode === 'door' && candidate.quad?.length === 4) {
                        const center = candidate.quad.reduce((sum, point) => [sum[0] + point[0] / 4, sum[1] + point[1] / 4], [0, 0]);
                        const factor = clamp(Number(correction.scale_factor || 1), 0.6, 1.4);
                        candidate.quad = candidate.quad.map(([qx, qy]) => [center[0] + (qx - center[0]) * factor + Number(correction.dx || 0), center[1] + (qy - center[1]) * factor + Number(correction.dy || 0)]);
                    }
                    if (!this.hardValid(candidate, dims)) break;
                    this.result = candidate;
                    this.draw(candidate, dims);
                } catch (_) {
                    this.warnings.push('Automatic visual checking was unavailable; the safe local fit is shown.');
                    break;
                }
            }
            this.result = best;
            this.draw(best, dims);
            if (bestScore >= 0) this.confidence = clamp(bestScore, 0, 1);
            this.report(best);
        }

        hardValid(place, dims) {
            if (place.mode === 'door') return Boolean(place.quad?.length === 4 && place.quad.flat().every((v) => v >= 0.02 && v <= 0.98));
            const w = place.width;
            const h = place.height;
            const rect = { left: place.x-w/2, right: place.x+w/2, top: place.y-h, bottom: place.y };
            if (rect.left < 0.02 || rect.right > 0.98 || rect.top < 0.02 || rect.bottom > 0.98) return false;
            if (place.mode === 'floor') {
                const foot = [[rect.left,place.y],[place.x,place.y],[rect.right,place.y],[rect.left,place.y-place.depth],[place.x,place.y-place.depth],[rect.right,place.y-place.depth]];
                if (!polygonInsidePolygon(foot,this.floor)) return false;
                if (this.blocked.some((shape) => polygonIntersectsRect(shape, rect))) return false;
                if (this.existing.some((box) => rectanglesOverlap(rect,box))) return false;
            }
            return Boolean(dims.width && dims.depth && dims.height);
        }

        report(place) {
            const confidence = this.confidence >= 0.75 ? 'High confidence' : this.confidence >= 0.5 ? 'Medium confidence' : 'Rough estimate';
            this.setStatus(confidence, confidence.startsWith('Rough') ? 'low' : 'good');
            if (this.guidance) this.guidance.textContent = this.warnings.length ? this.warnings.join(' ') : 'Furniture was placed automatically in the clearest compatible area.';
            if (this.detections) {
                const badges = [];
                if (this.floorDetected) badges.push('<span class="preview-badge">Floor detected</span>');
                const door = this.openingForDoor();
                if (door) badges.push('<span class="preview-badge">Door detected</span>');
                if (this.openings.some((item) => /window/i.test(`${item.type || ''} ${item.label || ''}`))) badges.push('<span class="preview-badge">Window detected</span>');
                badges.push(`<span class="preview-badge ${confidence.startsWith('Rough') ? 'is-rough' : ''}">${confidence}</span>`);
                this.detections.innerHTML = badges.join(' ');
            }
        }

        downloadPng() {
            this.canvas.toBlob((blob) => {
                if (!blob) return;
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'furnicraft-room-preview.png';
                link.click();
                URL.revokeObjectURL(url);
            }, 'image/png');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-room-preview]').forEach((frame) => {
            const engine = new RoomPreviewEngine(frame);
            engine.start();
            frame.roomPreviewEngine = engine;
        });
        document.addEventListener('click', async (event) => {
            const retryButton = event.target.closest('[data-preview-retry]');
            if (retryButton) {
                const engine = document.querySelector('[data-room-preview]')?.roomPreviewEngine;
                if (!engine || retryButton.disabled) return;
                retryButton.disabled = true;
                engine.retryAiPreview = true;
                try { await engine.start(); } finally { retryButton.disabled = false; }
                return;
            }
            const button = event.target.closest('[data-recommendation-preview]');
            if (!button) return;
            const frame = document.querySelector('[data-room-preview]');
            if (!frame?.roomPreviewEngine) return;
            const bounds = frame.getBoundingClientRect();
            if (bounds.top < 0 || bounds.bottom > window.innerHeight) frame.scrollIntoView({ behavior: 'smooth', block: 'center' });
            frame.roomPreviewEngine.selectProduct(button);
        });
    });
    window.RoomPreviewEngine = RoomPreviewEngine;
    window.RoomPreviewEngineTest = { pointInPolygon, polygonArea, bounds, rectanglesOverlap, polygonIntersectsRect, polygonInsidePolygon, weightedMedian };
})();
