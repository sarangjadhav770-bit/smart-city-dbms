var sceneLabel = document.getElementById('city-scene-status');
var cityCanvas = document.getElementById('city-canvas');
var resetViewButton = document.getElementById('city-reset-view');

async function startCivicPulseCity() {
    try {
        var modules = await Promise.all([
            import('https://cdn.jsdelivr.net/npm/three@0.170.0/build/three.module.js'),
            import('https://cdn.jsdelivr.net/npm/gsap@3.12.5/+esm')
        ]);
        buildCity(modules[0], modules[1].gsap || modules[1].default);
    } catch (error) {
        console.error('CivicPulse 3D city could not be loaded.', error);
        sceneLabel.textContent = 'City illustration · 3D needs an internet connection';
        cityCanvas.hidden = true;
    }
}

function buildCity(THREE, gsap) {
    var host = document.getElementById('city-world');
    var compact = window.matchMedia('(max-width: 767.98px)').matches || (navigator.hardwareConcurrency || 8) <= 4;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var renderer;
    try {
        renderer = new THREE.WebGLRenderer({
            canvas: cityCanvas,
            antialias: !compact,
            alpha: false,
            powerPreference: 'high-performance'
        });
    } catch (error) {
        console.error('WebGL is unavailable for the CivicPulse city.', error);
        sceneLabel.textContent = 'City illustration · 3D is unavailable on this device';
        cityCanvas.hidden = true;
        return;
    }

    var width = host.clientWidth;
    var height = host.clientHeight;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, compact ? 1.15 : 1.6));
    renderer.setSize(width, height, false);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.16;

    var scene = new THREE.Scene();
    scene.background = new THREE.Color(0x07101b);
    scene.fog = new THREE.FogExp2(0x07101b, 0.0085);

    var camera = new THREE.PerspectiveCamera(42, width / height, 0.1, 180);
    var orbit = { theta: 0.78, phi: 1.12, radius: 54 };
    var cameraTarget = new THREE.Vector3(0, 0, 0);
    var idleClock = 0;
    var cityOrigin = { latitude: 18.5204, longitude: 73.8567 };
    var metersPerCityUnit = 40;
    var dragging = false;
    var panning = false;
    var pointerTravelled = false;
    var activePointers = new Map();
    var previousPinchDistance = 0;
    var previousPointer = { x: 0, y: 0 };
    var cityRoot = new THREE.Group();
    scene.add(cityRoot);

    scene.add(new THREE.HemisphereLight(0x8db8da, 0x10131c, 1.35));
    var moonlight = new THREE.DirectionalLight(0xa6c9e8, 2.15);
    moonlight.position.set(-18, 30, 12);
    scene.add(moonlight);
    var cyanFill = new THREE.PointLight(0x42bfe8, 46, 34, 2);
    cyanFill.position.set(1, 8, 3);
    scene.add(cyanFill);
    var warmFill = new THREE.PointLight(0xf19e63, 24, 28, 2);
    warmFill.position.set(-14, 6, -13);
    scene.add(warmFill);

    function material(color, roughness, metalness, emissive, emissiveIntensity) {
        return new THREE.MeshStandardMaterial({
            color: color,
            roughness: roughness === undefined ? 0.82 : roughness,
            metalness: metalness || 0,
            emissive: emissive || 0x000000,
            emissiveIntensity: emissiveIntensity || 0
        });
    }

    var groundMaterial = material(0x18232c, 0.95);
    var roadMaterial = material(0x171f29, 0.94);
    var roadEdgeMaterial = material(0x526576, 0.65, 0.28);
    var buildingMaterials = [
        material(0x1d2b3c, 0.68, 0.2),
        material(0x243244, 0.62, 0.24),
        material(0x202c38, 0.7, 0.18),
        material(0x293342, 0.65, 0.24),
        material(0x1a2938, 0.63, 0.3)
    ];

    var ground = new THREE.Mesh(new THREE.PlaneGeometry(48, 48), groundMaterial);
    ground.rotation.x = -Math.PI / 2;
    ground.position.y = -1.02;
    cityRoot.add(ground);
    var plaza = new THREE.Mesh(new THREE.BoxGeometry(10.5, 0.08, 10.5), material(0x35414a, 0.78));
    plaza.position.set(0, 0.01, 0);
    cityRoot.add(plaza);

    var roadLines = [-12, 12];
    roadLines.forEach(function (axis) {
        var avenueX = new THREE.Mesh(new THREE.BoxGeometry(5.2, 0.12, 47), roadMaterial);
        avenueX.position.set(axis, 0.03, 0);
        cityRoot.add(avenueX);
        var avenueZ = new THREE.Mesh(new THREE.BoxGeometry(47, 0.12, 5.2), roadMaterial);
        avenueZ.position.set(0, 0.035, axis);
        cityRoot.add(avenueZ);
        [-1, 1].forEach(function (side) {
            var curbX = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.16, 47), roadEdgeMaterial);
            curbX.position.set(axis + side * 2.65, 0.03, 0);
            cityRoot.add(curbX);
            var curbZ = new THREE.Mesh(new THREE.BoxGeometry(47, 0.16, 0.12), roadEdgeMaterial);
            curbZ.position.set(0, 0.035, axis + side * 2.65);
            cityRoot.add(curbZ);
        });
    });

    var waterPipeMaterial = material(0x356780, 0.46, 0.48, 0x0b2d42, 0.24);
    var supplyPipe = new THREE.Mesh(new THREE.CylinderGeometry(0.13, 0.13, 42, 10), waterPipeMaterial);
    supplyPipe.rotation.z = Math.PI / 2;
    supplyPipe.position.set(0, 0.17, 15.1);
    cityRoot.add(supplyPipe);
    var valveMaterial = material(0x657d84, 0.44, 0.48);
    for (var valveX = -18; valveX <= 18; valveX += 9) {
        var valve = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.2, 0.35, 10), valveMaterial);
        valve.position.set(valveX, 0.25, 15.1);
        cityRoot.add(valve);
        var valveHandle = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.045, 0.06), valveMaterial);
        valveHandle.position.set(valveX, 0.46, 15.1);
        cityRoot.add(valveHandle);
    }

    var laneMarkMaterial = material(0xb2c5ce, 0.68, 0, 0x879ba6, 0.16);
    var laneMarkGeometryX = new THREE.BoxGeometry(1.05, 0.025, 0.09);
    var laneMarkGeometryZ = new THREE.BoxGeometry(0.09, 0.025, 1.05);
    roadLines.forEach(function (axis) {
        for (var lane = -20; lane <= 20; lane += 3.1) {
            [-1, 1].forEach(function (side) {
                var dashX = new THREE.Mesh(laneMarkGeometryX, laneMarkMaterial);
                dashX.position.set(lane, 0.103, axis + side * 1.22);
                cityRoot.add(dashX);
                var dashZ = new THREE.Mesh(laneMarkGeometryZ, laneMarkMaterial);
                dashZ.position.set(axis + side * 1.22, 0.108, lane);
                cityRoot.add(dashZ);
            });
        }
    });

    var randomSeed = 41723;
    function random() {
        randomSeed = (randomSeed * 16807) % 2147483647;
        return (randomSeed - 1) / 2147483646;
    }

    var windowGeometry = new THREE.BoxGeometry(0.14, 0.19, 0.045);
    var windowMaterial = new THREE.MeshStandardMaterial({
        color: 0xffffff,
        roughness: 0.38,
        emissive: 0xffc97c,
        emissiveIntensity: 1.35
    });
    var windowCapacity = compact ? 560 : 1120;
    var windows = new THREE.InstancedMesh(windowGeometry, windowMaterial, windowCapacity);
    windows.instanceMatrix.setUsage(THREE.StaticDrawUsage);
    var windowCount = 0;
    var buildingGeometry = new THREE.BoxGeometry(1, 1, 1);
    var buildingCount = 0;
    var buildingMeshes = [];

    function addWindows(x, z, w, d, h, color) {
        var rows = Math.max(2, Math.floor(h / 0.8));
        var columnsX = Math.max(2, Math.floor(w / 0.42));
        var columnsZ = Math.max(2, Math.floor(d / 0.42));
        var yStart = 0.56;
        function put(px, py, pz, ry) {
            if (windowCount >= windowCapacity || random() < 0.19) return;
            var transform = new THREE.Object3D();
            transform.position.set(px, py, pz);
            transform.rotation.y = ry;
            transform.scale.set(1, 0.9 + random() * 0.35, 1);
            transform.updateMatrix();
            windows.setMatrixAt(windowCount, transform.matrix);
            var warmth = random();
            windows.setColorAt(windowCount, warmth > 0.82 ? new THREE.Color(0x76d9ed) : color);
            windowCount += 1;
        }
        for (var floor = 0; floor < rows; floor += 1) {
            var y = yStart + floor * (h / (rows + 1));
            for (var ix = 0; ix < columnsX; ix += 1) {
                var wx = x - w / 2 + (ix + 0.5) * w / columnsX;
                put(wx, y, z + d / 2 + 0.018, 0);
                put(wx, y, z - d / 2 - 0.018, Math.PI);
            }
            for (var iz = 0; iz < columnsZ; iz += 1) {
                var wz = z - d / 2 + (iz + 0.5) * d / columnsZ;
                put(x + w / 2 + 0.018, y, wz, Math.PI / 2);
                put(x - w / 2 - 0.018, y, wz, -Math.PI / 2);
            }
        }
    }

    for (var bx = -20; bx <= 20; bx += 4) {
        for (var bz = -20; bz <= 20; bz += 4) {
            if (Math.abs(Math.abs(bx) - 12) < 3 || Math.abs(Math.abs(bz) - 12) < 3) continue;
            if (Math.abs(bx) < 6 && Math.abs(bz) < 6) continue;
            if (random() < 0.12) continue;
            var bw = 1.9 + random() * 1.25;
            var bd = 1.8 + random() * 1.25;
            var bh = 2.4 + Math.pow(random(), 1.6) * (compact ? 8 : 13);
            var building = new THREE.Mesh(buildingGeometry, buildingMaterials[Math.floor(random() * buildingMaterials.length)]);
            building.position.set(bx, bh / 2 + 0.16, bz);
            building.scale.set(bw, bh, bd);
            building.castShadow = false;
            cityRoot.add(building);
            buildingMeshes.push(building);
            addWindows(bx, bz, bw, bd, bh, random() > 0.25 ? new THREE.Color(0xffcb82) : new THREE.Color(0xb4e4ff));
            buildingCount += 1;

            if (bh > 10 && random() > 0.56) {
                var antenna = new THREE.Mesh(
                    new THREE.CylinderGeometry(0.035, 0.075, 1.25, 6),
                    material(0x6a8292, 0.5, 0.4)
                );
                antenna.position.set(bx, bh + 0.85, bz);
                cityRoot.add(antenna);
                var beacon = new THREE.Mesh(
                    new THREE.SphereGeometry(0.09, 8, 8),
                    new THREE.MeshBasicMaterial({ color: 0xff8a70 })
                );
                beacon.position.set(bx, bh + 1.52, bz);
                cityRoot.add(beacon);
            }
        }
    }

    windows.count = windowCount;
    windows.instanceMatrix.needsUpdate = true;
    if (windows.instanceColor) windows.instanceColor.needsUpdate = true;
    cityRoot.add(windows);

    var landmark = new THREE.Group();
    landmark.position.set(0, 0, 0);
    var civicBase = new THREE.Mesh(new THREE.BoxGeometry(4.6, 0.34, 3.4), material(0x66747b, 0.5, 0.36));
    civicBase.position.y = 0.24;
    landmark.add(civicBase);
    var civicTower = new THREE.Mesh(
        new THREE.BoxGeometry(2.35, 10.4, 2.25),
        material(0x314a5e, 0.42, 0.46, 0x123348, 0.32)
    );
    civicTower.position.y = 5.55;
    landmark.add(civicTower);
    var civicCrown = new THREE.Mesh(
        new THREE.BoxGeometry(2.75, 0.65, 2.55),
        material(0x56869b, 0.35, 0.55, 0x245d70, 0.58)
    );
    civicCrown.position.y = 10.9;
    landmark.add(civicCrown);
    var brandCanvas = document.createElement('canvas');
    brandCanvas.width = 512;
    brandCanvas.height = 256;
    var brandContext = brandCanvas.getContext('2d');
    brandContext.fillStyle = '#0a1a29';
    brandContext.fillRect(0, 0, 512, 256);
    brandContext.strokeStyle = '#67d2e8';
    brandContext.lineWidth = 5;
    brandContext.strokeRect(12, 12, 488, 232);
    brandContext.fillStyle = '#e9f8ff';
    brandContext.font = '700 58px Arial, sans-serif';
    brandContext.textAlign = 'center';
    brandContext.fillText('CIVICPULSE', 256, 116);
    brandContext.fillStyle = '#84d8e9';
    brandContext.font = '500 23px Arial, sans-serif';
    brandContext.fillText('PUNE · CITY OPERATIONS', 256, 164);
    var brandTexture = new THREE.CanvasTexture(brandCanvas);
    brandTexture.colorSpace = THREE.SRGBColorSpace;
    var brandSign = new THREE.Mesh(
        new THREE.PlaneGeometry(1.7, 0.85),
        new THREE.MeshBasicMaterial({ map: brandTexture, transparent: true, side: THREE.DoubleSide })
    );
    brandSign.position.set(0, 5.8, 1.14);
    landmark.add(brandSign);
    var landmarkLight = new THREE.PointLight(0x54c8e8, 38, 13, 2);
    landmarkLight.position.set(0, 9.5, 1.7);
    landmark.add(landmarkLight);
    cityRoot.add(landmark);

    var reservoir = new THREE.Group();
    reservoir.position.set(-19, 0, 19);
    for (var reservoirLeg = 0; reservoirLeg < 4; reservoirLeg += 1) {
        var legX = reservoirLeg % 2 ? 0.72 : -0.72;
        var legZ = reservoirLeg > 1 ? 0.72 : -0.72;
        var leg = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.14, 2.8, 7), material(0x526672, 0.62, 0.36));
        leg.position.set(legX, 1.65, legZ);
        reservoir.add(leg);
        var brace = new THREE.Mesh(new THREE.BoxGeometry(1.95, 0.06, 0.06), material(0x71858c, 0.55, 0.4));
        brace.position.set(0, 1.3, legZ);
        reservoir.add(brace);
    }
    var tankBody = new THREE.Mesh(
        new THREE.CylinderGeometry(1.18, 1.18, 1.75, 14),
        material(0x426e83, 0.4, 0.44, 0x102e42, 0.35)
    );
    tankBody.position.y = 3.95;
    reservoir.add(tankBody);
    var tankCap = new THREE.Mesh(
        new THREE.CylinderGeometry(1.18, 0.82, 0.38, 14),
        material(0x698491, 0.42, 0.38)
    );
    tankCap.position.y = 5.02;
    reservoir.add(tankCap);
    var tankBand = new THREE.Mesh(
        new THREE.TorusGeometry(1.19, 0.055, 7, 24),
        material(0x6bb8cb, 0.35, 0.5, 0x1b6475, 0.45)
    );
    tankBand.rotation.x = Math.PI / 2;
    tankBand.position.y = 4;
    reservoir.add(tankBand);
    cityRoot.add(reservoir);

    var treeTrunkGeometry = new THREE.CylinderGeometry(0.12, 0.18, 1, 5);
    var treeCrownGeometry = new THREE.IcosahedronGeometry(0.72, 1);
    var treeTrunks = new THREE.InstancedMesh(treeTrunkGeometry, material(0x544d42, 0.95), compact ? 64 : 110);
    var treeCrowns = new THREE.InstancedMesh(treeCrownGeometry, material(0x254d45, 0.92, 0, 0x102820, 0.12), compact ? 64 : 110);
    var treeObject = new THREE.Object3D();
    var treeCount = 0;
    for (var tx = -20; tx <= 20; tx += 4) {
        for (var tz = -20; tz <= 20; tz += 4) {
            if (Math.abs(Math.abs(tx) - 12) < 4 || Math.abs(Math.abs(tz) - 12) < 4) continue;
            if (Math.abs(tx) < 6 && Math.abs(tz) < 6) continue;
            if (random() < 0.52 || treeCount >= treeTrunks.count) continue;
            var treeScale = 0.65 + random() * 0.5;
            treeObject.position.set(tx + (random() - 0.5) * 2.4, 0.66, tz + (random() - 0.5) * 2.4);
            treeObject.scale.setScalar(treeScale);
            treeObject.updateMatrix();
            treeTrunks.setMatrixAt(treeCount, treeObject.matrix);
            treeObject.position.y = 1.45 * treeScale;
            treeObject.updateMatrix();
            treeCrowns.setMatrixAt(treeCount, treeObject.matrix);
            treeCount += 1;
        }
    }
    treeTrunks.count = treeCount;
    treeCrowns.count = treeCount;
    cityRoot.add(treeTrunks, treeCrowns);

    var lightPoleMaterial = material(0x65727a, 0.42, 0.5);
    var lampMaterial = new THREE.MeshStandardMaterial({ color: 0xffe6bb, emissive: 0xffc77d, emissiveIntensity: 2.1 });
    var metroTrackMaterial = material(0x536673, 0.46, 0.52);
    var metroRailMaterial = material(0x9caeb3, 0.38, 0.6);
    var trafficCars = [];
    var droneGroups = [];
    var metroTrains = [];
    var carColors = [0x268eb1, 0xb8584c, 0x9db3bd, 0xc49b61, 0x476d87, 0x708b79];
    var cityChunkSize = 48;
    var cityChunkRadius = compact ? 1 : 2;
    var cityChunks = new Map();
    var loadedChunkCenter = '';
    var chunkGroundGeometry = new THREE.PlaneGeometry(cityChunkSize, cityChunkSize);
    var chunkRoadXGeometry = new THREE.BoxGeometry(cityChunkSize, 0.12, 5.2);
    var chunkRoadZGeometry = new THREE.BoxGeometry(5.2, 0.12, cityChunkSize);
    var chunkDashXGeometry = new THREE.BoxGeometry(1.05, 0.025, 0.09);
    var chunkDashZGeometry = new THREE.BoxGeometry(0.09, 0.025, 1.05);
    var chunkBuildingGeometry = new THREE.BoxGeometry(1, 1, 1);
    var chunkWindowGeometry = new THREE.BoxGeometry(0.14, 0.19, 0.045);
    var chunkTreeTrunkGeometry = new THREE.CylinderGeometry(0.12, 0.18, 1, 5);
    var chunkTreeCrownGeometry = new THREE.IcosahedronGeometry(0.72, 1);
    var chunkLampPoleGeometry = new THREE.CylinderGeometry(0.035, 0.055, 2.8, 6);
    var chunkLampGeometry = new THREE.SphereGeometry(0.105, 8, 8);
    var chunkMetroGeometry = new THREE.BoxGeometry(cityChunkSize, 0.32, 0.46);
    var chunkMetroRailGeometry = new THREE.BoxGeometry(cityChunkSize, 0.09, 0.055);
    var chunkMetroSupportGeometry = new THREE.BoxGeometry(0.5, 4.2, 0.5);
    var metroCarGeometry = new THREE.BoxGeometry(2.65, 1.02, 0.92);
    var metroWindowGeometry = new THREE.BoxGeometry(2.34, 0.37, 0.045);
    var metroLightGeometry = new THREE.SphereGeometry(0.075, 8, 8);
    var metroCarMaterial = material(0x9cb6c0, 0.38, 0.38);
    var metroWindowMaterial = new THREE.MeshBasicMaterial({ color: 0x8bd3e4 });
    var metroHeadlightMaterial = new THREE.MeshBasicMaterial({ color: 0xe7fbff });
    var metroTailLightMaterial = new THREE.MeshBasicMaterial({ color: 0xff746d });
    var chunkBuildingMaterials = buildingMaterials.concat([buildingMaterials[0]]);

    function chunkRandom(seed) {
        var state = seed % 2147483647;
        if (state <= 0) state += 2147483646;
        return function () {
            state = (state * 16807) % 2147483647;
            return (state - 1) / 2147483646;
        };
    }

    function addCityChunk(chunkX, chunkZ) {
        if (chunkX === 0 && chunkZ === 0) return null;
        var key = chunkX + ':' + chunkZ;
        if (cityChunks.has(key)) return cityChunks.get(key);

        var group = new THREE.Group();
        group.position.set(chunkX * cityChunkSize, 0, chunkZ * cityChunkSize);
        var seed = Math.abs((chunkX * 73856093) ^ (chunkZ * 19349663) ^ 41723);
        var chunkRand = chunkRandom(seed);
        var chunkGround = new THREE.Mesh(chunkGroundGeometry, groundMaterial);
        chunkGround.rotation.x = -Math.PI / 2;
        chunkGround.position.y = -1.02;
        group.add(chunkGround);

        [-12, 12].forEach(function (axis) {
            var roadX = new THREE.Mesh(chunkRoadXGeometry, roadMaterial);
            roadX.position.set(0, 0.03, axis);
            var roadZ = new THREE.Mesh(chunkRoadZGeometry, roadMaterial);
            roadZ.position.set(axis, 0.035, 0);
            group.add(roadX, roadZ);
        });

        var buildings = new THREE.InstancedMesh(chunkBuildingGeometry, chunkBuildingMaterials, 18);
        var windowsPerChunk = compact ? 650 : 1250;
        var chunkWindows = new THREE.InstancedMesh(chunkWindowGeometry, windowMaterial, windowsPerChunk);
        var buildingDummy = new THREE.Object3D();
        var windowDummy = new THREE.Object3D();
        var buildingIndex = 0;
        var windowIndex = 0;

        function putChunkWindow(x, y, z, rotation, tint) {
            if (windowIndex >= windowsPerChunk || chunkRand() < 0.24) return;
            windowDummy.position.set(x, y, z);
            windowDummy.rotation.set(0, rotation, 0);
            windowDummy.scale.set(1, 0.9 + chunkRand() * 0.3, 1);
            windowDummy.updateMatrix();
            chunkWindows.setMatrixAt(windowIndex, windowDummy.matrix);
            chunkWindows.setColorAt(windowIndex, tint);
            windowIndex += 1;
        }

        var buildingTintWarm = new THREE.Color(0xffcb82);
        var buildingTintCool = new THREE.Color(0xb4e4ff);
        [-19, -5, 5, 19].forEach(function (bx) {
            [-19, -5, 5, 19].forEach(function (bz) {
                if (buildingIndex >= buildings.count || chunkRand() < 0.13) return;
                var width = 2.1 + chunkRand() * 1.4;
                var depth = 2.1 + chunkRand() * 1.4;
                var height = 3 + Math.pow(chunkRand(), 1.35) * (compact ? 8 : 13);
                buildingDummy.position.set(bx, height / 2 + 0.14, bz);
                buildingDummy.scale.set(width, height, depth);
                buildingDummy.updateMatrix();
                buildings.setMatrixAt(buildingIndex, buildingDummy.matrix);
                buildings.setColorAt(buildingIndex, buildingTintWarm);
                buildingIndex += 1;
                var rows = Math.max(2, Math.floor(height / 0.8));
                var columnsX = Math.max(2, Math.floor(width / 0.42));
                var columnsZ = Math.max(2, Math.floor(depth / 0.42));
                var tint = chunkRand() > 0.25 ? buildingTintWarm : buildingTintCool;
                for (var floorIndex = 0; floorIndex < rows; floorIndex += 1) {
                    var windowY = 0.5 + floorIndex * height / (rows + 1);
                    for (var columnX = 0; columnX < columnsX; columnX += 1) {
                        var windowX = bx - width / 2 + (columnX + 0.5) * width / columnsX;
                        putChunkWindow(windowX, windowY, bz + depth / 2 + 0.018, 0, tint);
                        putChunkWindow(windowX, windowY, bz - depth / 2 - 0.018, Math.PI, tint);
                    }
                    for (var columnZ = 0; columnZ < columnsZ; columnZ += 1) {
                        var windowZ = bz - depth / 2 + (columnZ + 0.5) * depth / columnsZ;
                        putChunkWindow(bx + width / 2 + 0.018, windowY, windowZ, Math.PI / 2, tint);
                        putChunkWindow(bx - width / 2 - 0.018, windowY, windowZ, -Math.PI / 2, tint);
                    }
                }
            });
        });
        buildings.count = buildingIndex;
        buildings.instanceMatrix.needsUpdate = true;
        if (buildings.instanceColor) buildings.instanceColor.needsUpdate = true;
        buildingMeshes.push(buildings);
        chunkWindows.count = windowIndex;
        chunkWindows.instanceMatrix.needsUpdate = true;
        if (chunkWindows.instanceColor) chunkWindows.instanceColor.needsUpdate = true;
        group.add(buildings, chunkWindows);

        var chunkTreeTrunks = new THREE.InstancedMesh(chunkTreeTrunkGeometry, treeTrunks.material, 42);
        var chunkTreeCrowns = new THREE.InstancedMesh(chunkTreeCrownGeometry, treeCrowns.material, 42);
        var treeIndex = 0;
        for (var treeX = -21; treeX <= 21; treeX += 6) {
            for (var treeZ = -21; treeZ <= 21; treeZ += 6) {
                if (Math.abs(Math.abs(treeX) - 12) < 4 || Math.abs(Math.abs(treeZ) - 12) < 4 ||
                    chunkRand() < 0.55 || treeIndex >= chunkTreeTrunks.count) continue;
                var treeScale = 0.65 + chunkRand() * 0.5;
                buildingDummy.position.set(treeX + (chunkRand() - 0.5) * 2, 0.66, treeZ);
                buildingDummy.scale.setScalar(treeScale);
                buildingDummy.updateMatrix();
                chunkTreeTrunks.setMatrixAt(treeIndex, buildingDummy.matrix);
                buildingDummy.position.y = 1.45 * treeScale;
                buildingDummy.updateMatrix();
                chunkTreeCrowns.setMatrixAt(treeIndex, buildingDummy.matrix);
                treeIndex += 1;
            }
        }
        chunkTreeTrunks.count = treeIndex;
        chunkTreeCrowns.count = treeIndex;
        chunkTreeTrunks.instanceMatrix.needsUpdate = true;
        chunkTreeCrowns.instanceMatrix.needsUpdate = true;
        group.add(chunkTreeTrunks, chunkTreeCrowns);

        var dashCount = 128;
        var dashesX = new THREE.InstancedMesh(chunkDashXGeometry, laneMarkMaterial, dashCount);
        var dashesZ = new THREE.InstancedMesh(chunkDashZGeometry, laneMarkMaterial, dashCount);
        var dashIndex = 0;
        [-12, 12].forEach(function (axis) {
            for (var dashPosition = -22; dashPosition <= 22; dashPosition += 3.1) {
                [-1, 1].forEach(function (side) {
                    buildingDummy.position.set(dashPosition, 0.103, axis + side * 1.22);
                    buildingDummy.updateMatrix();
                    dashesX.setMatrixAt(dashIndex, buildingDummy.matrix);
                    buildingDummy.position.set(axis + side * 1.22, 0.108, dashPosition);
                    buildingDummy.updateMatrix();
                    dashesZ.setMatrixAt(dashIndex, buildingDummy.matrix);
                    dashIndex += 1;
                });
            }
        });
        dashesX.count = dashIndex;
        dashesZ.count = dashIndex;
        dashesX.instanceMatrix.needsUpdate = true;
        dashesZ.instanceMatrix.needsUpdate = true;
        group.add(dashesX, dashesZ);

        var lampPositions = [];
        for (var lampLane = -2; lampLane <= 2; lampLane += 1) {
            [-1, 1].forEach(function (side) {
                lampPositions.push([12 + side * 3.1, lampLane * 8]);
                lampPositions.push([lampLane * 8, 12 + side * 3.1]);
            });
        }
        var chunkPoles = new THREE.InstancedMesh(chunkLampPoleGeometry, lightPoleMaterial, lampPositions.length);
        var chunkLamps = new THREE.InstancedMesh(chunkLampGeometry, lampMaterial, lampPositions.length);
        lampPositions.forEach(function (position, index) {
            buildingDummy.position.set(position[0], 1.4, position[1]);
            buildingDummy.updateMatrix();
            chunkPoles.setMatrixAt(index, buildingDummy.matrix);
            buildingDummy.position.set(position[0] + 0.46, 2.67, position[1]);
            buildingDummy.scale.setScalar(1);
            buildingDummy.updateMatrix();
            chunkLamps.setMatrixAt(index, buildingDummy.matrix);
        });
        chunkPoles.instanceMatrix.needsUpdate = true;
        chunkLamps.instanceMatrix.needsUpdate = true;
        group.add(chunkPoles, chunkLamps);

        var chunkMetro = new THREE.Mesh(
            chunkMetroGeometry,
            metroTrackMaterial
        );
        chunkMetro.position.set(0, 4.25, 5.8);
        group.add(chunkMetro);
        var chunkRailA = new THREE.Mesh(chunkMetroRailGeometry, metroRailMaterial);
        var chunkRailB = new THREE.Mesh(chunkMetroRailGeometry, metroRailMaterial);
        chunkRailA.position.set(0, 4.46, 5.61);
        chunkRailB.position.set(0, 4.46, 5.99);
        group.add(chunkRailA, chunkRailB);
        for (var chunkSupportX = -20; chunkSupportX <= 20; chunkSupportX += 10) {
            var chunkSupport = new THREE.Mesh(chunkMetroSupportGeometry, metroTrackMaterial);
            chunkSupport.position.set(chunkSupportX, 2.1, 5.8);
            group.add(chunkSupport);
        }
        var chunkTrain = new THREE.Group();
        for (var trainCarIndex = 0; trainCarIndex < 4; trainCarIndex += 1) {
            var chunkTrainCar = new THREE.Mesh(metroCarGeometry, metroCarMaterial);
            chunkTrainCar.position.set(-trainCarIndex * 2.67, 0, 0);
            chunkTrain.add(chunkTrainCar);
            var chunkTrainWindow = new THREE.Mesh(metroWindowGeometry, metroWindowMaterial);
            chunkTrainWindow.position.set(-trainCarIndex * 2.67, 0.08, 0.48);
            chunkTrain.add(chunkTrainWindow);
            var chunkTrainLight = new THREE.Mesh(
                metroLightGeometry,
                trainCarIndex === 0 ? metroTailLightMaterial : metroHeadlightMaterial
            );
            chunkTrainLight.position.set(-trainCarIndex * 2.67 + 1.24, -0.2, 0.5);
            chunkTrain.add(chunkTrainLight);
        }
        chunkTrain.position.set(-24, 4.95, 5.8);
        group.add(chunkTrain);
        metroTrains.push({ mesh: chunkTrain, chunk: group, phase: chunkRand() * cityChunkSize });
        for (var vehicleIndex = 0; vehicleIndex < (compact ? 1 : 2); vehicleIndex += 1) {
            var laneOffset = vehicleIndex % 2 ? 0.8 : -0.8;
            var initialOffset = -18 + vehicleIndex * 22;
            addCar('x', 12 + laneOffset, initialOffset, vehicleIndex % 2 ? 3.2 : -2.8,
                { min: -cityChunkSize / 2, max: cityChunkSize / 2 }, group);
            addCar('z', -12 + laneOffset, initialOffset, vehicleIndex % 2 ? -3 : 3.1,
                { min: -cityChunkSize / 2, max: cityChunkSize / 2 }, group);
        }
        if (!compact && chunkRand() > 0.62) {
            addDrone(chunkX * cityChunkSize + (chunkRand() - 0.5) * 28, 10 + chunkRand() * 3,
                chunkZ * cityChunkSize + (chunkRand() - 0.5) * 28, 0.2 + chunkRand() * 0.15,
                chunkRand() * Math.PI * 2, group);
        }
        cityRoot.add(group);
        cityChunks.set(key, group);
        return group;
    }

    function refreshCityChunks() {
        var centerX = Math.floor(cameraTarget.x / cityChunkSize);
        var centerZ = Math.floor(cameraTarget.z / cityChunkSize);
        var centerKey = centerX + ':' + centerZ;
        if (centerKey === loadedChunkCenter) return;
        loadedChunkCenter = centerKey;
        var keep = new Set();
        for (var chunkX = centerX - cityChunkRadius; chunkX <= centerX + cityChunkRadius; chunkX += 1) {
            for (var chunkZ = centerZ - cityChunkRadius; chunkZ <= centerZ + cityChunkRadius; chunkZ += 1) {
                var key = chunkX + ':' + chunkZ;
                keep.add(key);
                addCityChunk(chunkX, chunkZ);
            }
        }
        cityChunks.forEach(function (group, key) {
            if (keep.has(key)) return;
            cityRoot.remove(group);
            trafficCars = trafficCars.filter(function (car) { return car.chunk !== group; });
            buildingMeshes = buildingMeshes.filter(function (mesh) { return mesh.parent !== group; });
            metroTrains = metroTrains.filter(function (metro) { return metro.chunk !== group; });
            if (hoveredBuilding && hoveredBuilding.object.parent === group) {
                hoveredBuilding = null;
                if (hoverRing) hoverRing.visible = false;
            }
            droneGroups = droneGroups.filter(function (drone) {
                if (drone.chunk !== group) return true;
                group.remove(drone.mesh);
                drone.mesh.traverse(function (object) {
                    if (object.geometry) object.geometry.dispose();
                    if (object.material && object.material !== lightPoleMaterial) object.material.dispose();
                });
                return false;
            });
            group.traverse(function (object) {
                if (object.isInstancedMesh && typeof object.dispose === 'function') object.dispose();
            });
            cityChunks.delete(key);
        });
    }

    refreshCityChunks();

    for (var roadIndex = 0; roadIndex < roadLines.length; roadIndex += 1) {
        var roadLine = roadLines[roadIndex];
        for (var lightIndex = -2; lightIndex <= 2; lightIndex += 1) {
            [-1, 1].forEach(function (side) {
                var lampX = roadIndex === 0 ? roadLine + side * 3.1 : lightIndex * 8;
                var lampZ = roadIndex === 0 ? lightIndex * 8 : roadLine + side * 3.1;
                if (Math.abs(lampX) > 22 || Math.abs(lampZ) > 22) return;
                var pole = new THREE.Mesh(new THREE.CylinderGeometry(0.035, 0.055, 2.8, 6), lightPoleMaterial);
                pole.position.set(lampX, 1.4, lampZ);
                cityRoot.add(pole);
                var arm = new THREE.Mesh(new THREE.BoxGeometry(0.55, 0.055, 0.055), lightPoleMaterial);
                arm.position.set(lampX + 0.24, 2.72, lampZ);
                cityRoot.add(arm);
                var lamp = new THREE.Mesh(new THREE.SphereGeometry(0.105, 8, 8), lampMaterial);
                lamp.position.set(lampX + 0.46, 2.67, lampZ);
                cityRoot.add(lamp);
            });
        }
    }

    var metroTrack = new THREE.Mesh(new THREE.BoxGeometry(47, 0.32, 0.46), metroTrackMaterial);
    metroTrack.position.set(0, 4.25, 5.8);
    cityRoot.add(metroTrack);
    var railA = new THREE.Mesh(new THREE.BoxGeometry(47, 0.09, 0.055), metroRailMaterial);
    var railB = railA.clone();
    railA.position.set(0, 4.46, 5.61);
    railB.position.set(0, 4.46, 5.99);
    cityRoot.add(railA, railB);
    for (var supportX = -20; supportX <= 20; supportX += 10) {
        var support = new THREE.Mesh(new THREE.BoxGeometry(0.5, 4.2, 0.5), material(0x4d626e, 0.68, 0.28));
        support.position.set(supportX, 2.1, 5.8);
        cityRoot.add(support);
    }

    var train = new THREE.Group();
    var trainBaseMaterial = material(0x9cb6c0, 0.38, 0.38);
    for (var carIndex = 0; carIndex < 4; carIndex += 1) {
        var trainCar = new THREE.Mesh(new THREE.BoxGeometry(2.65, 1.02, 0.92), trainBaseMaterial);
        trainCar.position.x = -carIndex * 2.67;
        train.add(trainCar);
        var trainWindow = new THREE.Mesh(
            new THREE.BoxGeometry(2.34, 0.37, 0.045),
            new THREE.MeshBasicMaterial({ color: 0x8bd3e4 })
        );
        trainWindow.position.set(-carIndex * 2.67, 0.08, 0.48);
        train.add(trainWindow);
        var trainLight = new THREE.Mesh(
            new THREE.SphereGeometry(0.075, 8, 8),
            new THREE.MeshBasicMaterial({ color: carIndex === 0 ? 0xff746d : 0xe7fbff })
        );
        trainLight.position.set(-carIndex * 2.67 + 1.24, -0.2, 0.5);
        train.add(trainLight);
    }
    train.position.set(-23, 4.95, 5.8);
    cityRoot.add(train);

    function addCar(axis, lane, offset, speed, bounds, parent) {
        var vehicle = new THREE.Group();
        var body = new THREE.Mesh(
            new THREE.BoxGeometry(axis === 'x' ? 1.2 : 0.72, 0.34, axis === 'x' ? 0.72 : 1.2),
            material(carColors[Math.floor(random() * carColors.length)], 0.4, 0.35)
        );
        body.position.y = 0.35;
        vehicle.add(body);
        var roof = new THREE.Mesh(
            new THREE.BoxGeometry(axis === 'x' ? 0.56 : 0.48, 0.2, axis === 'x' ? 0.5 : 0.56),
            material(0x243542, 0.38, 0.3)
        );
        roof.position.set(0, 0.58, 0);
        vehicle.add(roof);
        var frontZ = axis === 'x' ? 0.37 : 0.6;
        var frontX = axis === 'x' ? 0.59 : 0.27;
        [-1, 1].forEach(function (side) {
            var head = new THREE.Mesh(
                new THREE.BoxGeometry(0.18, 0.09, 0.055),
                new THREE.MeshBasicMaterial({ color: 0xffe8b3 })
            );
            head.position.set(axis === 'x' ? side * frontX : side * 0.19, 0.4, axis === 'x' ? frontZ : frontZ * 0.78);
            vehicle.add(head);
            var tail = new THREE.Mesh(
                new THREE.BoxGeometry(0.16, 0.075, 0.045),
                new THREE.MeshBasicMaterial({ color: 0xff554b })
            );
            tail.position.set(axis === 'x' ? side * frontX : side * 0.19, 0.4, axis === 'x' ? -frontZ : -frontZ * 0.78);
            vehicle.add(tail);
        });
        if (axis === 'x') {
            vehicle.position.set(offset, 0, lane);
            if (speed < 0) vehicle.rotation.y = Math.PI;
        } else {
            vehicle.position.set(lane, 0, offset);
            vehicle.rotation.y = speed < 0 ? Math.PI / 2 : -Math.PI / 2;
        }
        (parent || cityRoot).add(vehicle);
        trafficCars.push({
            mesh: vehicle,
            axis: axis,
            lane: lane,
            offset: offset,
            speed: speed,
            min: bounds ? bounds.min : -24,
            max: bounds ? bounds.max : 24,
            chunk: parent || null
        });
    }
    for (var trafficRoad = 0; trafficRoad < roadLines.length; trafficRoad += 1) {
        var trafficLine = roadLines[trafficRoad];
        for (var vehicleIndex = 0; vehicleIndex < (compact ? 3 : 5); vehicleIndex += 1) {
            var trafficOffset = -20 + vehicleIndex * (40 / (compact ? 3 : 5)) + random() * 2;
            addCar('x', trafficLine + (vehicleIndex % 2 ? 1 : -1) * 0.8, trafficOffset, vehicleIndex % 2 ? 3 + random() * 1.4 : -2.6 - random());
            addCar('z', trafficLine + (vehicleIndex % 2 ? -1 : 1) * 0.8, trafficOffset, vehicleIndex % 2 ? -2.8 - random() : 3 + random());
        }
    }

    function addDrone(x, y, z, speed, phase, parent) {
        var drone = new THREE.Group();
        var shell = new THREE.Mesh(
            new THREE.BoxGeometry(0.56, 0.14, 0.35),
            material(0x546a79, 0.4, 0.55)
        );
        drone.add(shell);
        [-1, 1].forEach(function (sideX) {
            [-1, 1].forEach(function (sideZ) {
                var armPart = new THREE.Mesh(
                    new THREE.BoxGeometry(0.37, 0.035, 0.035),
                    lightPoleMaterial
                );
                armPart.position.set(sideX * 0.3, 0.025, sideZ * 0.2);
                armPart.rotation.y = sideX === sideZ ? Math.PI / 4 : -Math.PI / 4;
                drone.add(armPart);
                var rotor = new THREE.Mesh(
                    new THREE.CylinderGeometry(0.13, 0.13, 0.025, 12),
                    material(0x7895a5, 0.38, 0.48)
                );
                rotor.position.set(sideX * 0.43, 0.045, sideZ * 0.3);
                drone.add(rotor);
            });
        });
        var navLight = new THREE.PointLight(0x59d5ee, 4, 5);
        navLight.position.set(0, -0.13, 0);
        drone.add(navLight);
        drone.position.set(parent ? x - parent.position.x : x, y, parent ? z - parent.position.z : z);
        (parent || scene).add(drone);
        droneGroups.push({ mesh: drone, speed: speed, phase: phase, x: drone.position.x, z: drone.position.z, chunk: parent || null });
    }
    addDrone(-13, 11, -9, 0.32, 0.4);
    if (!compact) addDrone(15, 13, 8, 0.24, 2.1);

    var particleCount = compact ? 130 : 330;
    var particlePositions = new Float32Array(particleCount * 3);
    for (var particleIndex = 0; particleIndex < particleCount; particleIndex += 1) {
        particlePositions[particleIndex * 3] = (random() - 0.5) * 52;
        particlePositions[particleIndex * 3 + 1] = 1 + random() * 18;
        particlePositions[particleIndex * 3 + 2] = (random() - 0.5) * 52;
    }
    var particleGeometry = new THREE.BufferGeometry();
    particleGeometry.setAttribute('position', new THREE.BufferAttribute(particlePositions, 3));
    var particles = new THREE.Points(
        particleGeometry,
        new THREE.PointsMaterial({ color: 0x81d0e0, size: 0.075, transparent: true, opacity: 0.5, depthWrite: false })
    );
    scene.add(particles);

    var highlightRing = new THREE.Mesh(
        new THREE.TorusGeometry(2.3, 0.055, 8, 72),
        new THREE.MeshBasicMaterial({ color: 0x55d2e8, transparent: true, opacity: 0 })
    );
    highlightRing.rotation.x = -Math.PI / 2;
    highlightRing.position.set(-8, 0.22, -8);
    cityRoot.add(highlightRing);
    var highlightAnchor = new THREE.Mesh(
        new THREE.SphereGeometry(0.21, 12, 12),
        new THREE.MeshBasicMaterial({ color: 0x76e2ed, transparent: true, opacity: 0 })
    );
    highlightAnchor.position.set(-8, 0.36, -8);
    cityRoot.add(highlightAnchor);
    var categoryColors = {
        Roads: 0x58c9ec,
        Traffic: 0xf3b568,
        Garbage: 0x81d49b,
        Electricity: 0xffd078,
        Streetlight: 0xffd078,
        Water: 0x68b8f5,
        'Water Leakage': 0x68b8f5,
        Drainage: 0x5ca7dc,
        'Public Health': 0x77d6b1,
        Police: 0xa2b4ff
    };
    var categoryPositions = {
        Roads: [-12, -8],
        Traffic: [12, 12],
        Garbage: [-17, 15],
        Electricity: [12, -8],
        Streetlight: [8, 15],
        Water: [-8, 15],
        'Water Leakage': [-8, 15],
        Drainage: [8, 16],
        'Public Health': [-17, -15],
        Police: [0, 0]
    };

    var locationMarker = new THREE.Group();
    var locationRing = new THREE.Mesh(
        new THREE.TorusGeometry(0.8, 0.06, 8, 40),
        new THREE.MeshBasicMaterial({ color: 0x7ce0ed, transparent: true, opacity: 0.92 })
    );
    locationRing.rotation.x = -Math.PI / 2;
    locationMarker.add(locationRing);
    var locationCore = new THREE.Mesh(
        new THREE.SphereGeometry(0.2, 12, 12),
        new THREE.MeshBasicMaterial({ color: 0xe8fcff })
    );
    locationCore.position.y = 0.72;
    locationMarker.add(locationCore);
    var locationBeam = new THREE.Mesh(
        new THREE.CylinderGeometry(0.015, 0.12, 0.8, 12, 1, true),
        new THREE.MeshBasicMaterial({ color: 0x57cde4, transparent: true, opacity: 0.24, side: THREE.DoubleSide })
    );
    locationBeam.position.y = 0.32;
    locationMarker.add(locationBeam);
    locationMarker.visible = false;
    cityRoot.add(locationMarker);
    var locationLight = new THREE.PointLight(0x55cbe7, 16, 9);
    locationLight.position.y = 1.7;
    locationMarker.add(locationLight);
    var hoveredBuilding = null;
    var buildingRaycaster = new THREE.Raycaster();
    var buildingPointer = new THREE.Vector2();
    var hoverRing = new THREE.Mesh(
        new THREE.TorusGeometry(2.3, 0.045, 8, 48),
        new THREE.MeshBasicMaterial({ color: 0x80d8ea, transparent: true, opacity: 0 })
    );
    hoverRing.rotation.x = -Math.PI / 2;
    hoverRing.position.y = 0.2;
    cityRoot.add(hoverRing);
    var categoryMessage = document.querySelector('#city-category-signal span');

    function updateBuildingHover(event) {
        var bounds = cityCanvas.getBoundingClientRect();
        buildingPointer.x = ((event.clientX - bounds.left) / bounds.width) * 2 - 1;
        buildingPointer.y = -((event.clientY - bounds.top) / bounds.height) * 2 + 1;
        buildingRaycaster.setFromCamera(buildingPointer, camera);
        var buildingHits = buildingRaycaster.intersectObjects(buildingMeshes, false);
        var buildingHit = buildingHits.length ? buildingHits[0] : null;
        var buildingObject = buildingHit ? buildingHit.object : null;
        var instanceId = buildingHit && buildingHit.instanceId !== undefined ? buildingHit.instanceId : null;
        var buildingKey = buildingObject ? buildingObject.uuid + ':' + instanceId : '';
        if (hoveredBuilding && hoveredBuilding.key === buildingKey) return;
        if (buildingObject) {
            var localPosition = new THREE.Vector3();
            var footprintScale = 1;
            if (instanceId !== null) {
                var instanceMatrix = new THREE.Matrix4();
                buildingObject.getMatrixAt(instanceId, instanceMatrix);
                localPosition.setFromMatrixPosition(instanceMatrix);
                var instanceScale = new THREE.Vector3().setFromMatrixScale(instanceMatrix);
                footprintScale = Math.max(instanceScale.x, instanceScale.z) / 4.6;
            } else {
                localPosition.copy(buildingObject.position);
                footprintScale = Math.max(buildingObject.scale.x, buildingObject.scale.z) / 4.6;
            }
            buildingObject.localToWorld(localPosition);
            hoveredBuilding = { object: buildingObject, point: localPosition, key: buildingKey, footprintScale: footprintScale };
        } else {
            hoveredBuilding = null;
        }
        hoverRing.visible = Boolean(hoveredBuilding);
        if (hoveredBuilding) {
            hoverRing.position.set(hoveredBuilding.point.x, 0.2, hoveredBuilding.point.z);
            hoverRing.scale.setScalar(hoveredBuilding.footprintScale);
            gsap.to(hoverRing.material, { opacity: 0.86, duration: 0.18 });
            cityCanvas.style.cursor = 'pointer';
            if (categoryMessage) categoryMessage.textContent = 'City block · click to explore this district';
        } else {
            gsap.to(hoverRing.material, { opacity: 0, duration: 0.18 });
            cityCanvas.style.cursor = 'grab';
            if (categoryMessage) categoryMessage.textContent = window.civicPulseCityState && window.civicPulseCityState.category
                ? 'Highlighting ' + window.civicPulseCityState.category + ' infrastructure across the city'
                : 'Select a service to highlight its city network';
        }
    }

    var wasteStation = new THREE.Group();
    wasteStation.position.set(-17, 0, 15);
    var wasteBodyMaterial = material(0x45675f, 0.8, 0.12);
    for (var wasteBin = 0; wasteBin < 3; wasteBin += 1) {
        var binX = (wasteBin - 1) * 0.8;
        var bin = new THREE.Mesh(new THREE.BoxGeometry(0.58, 0.78, 0.62), wasteBodyMaterial);
        bin.position.set(binX, 0.52, 0);
        wasteStation.add(bin);
        var binLid = new THREE.Mesh(new THREE.BoxGeometry(0.66, 0.09, 0.68), material(0x66847a, 0.7, 0.16));
        binLid.position.set(binX, 0.96, 0);
        wasteStation.add(binLid);
    }
    cityRoot.add(wasteStation);

    function updateCamera() {
        var spherical = new THREE.Spherical(orbit.radius, orbit.phi, orbit.theta);
        camera.position.setFromSpherical(spherical).add(cameraTarget);
        camera.lookAt(cameraTarget);
        camera.updateMatrixWorld(true);
        refreshCityChunks();
    }
    updateCamera();

    function flyTo(point, zoom) {
        gsap.to(cameraTarget, {
            x: point.x,
            y: 0,
            z: point.z,
            duration: reducedMotion ? 0.15 : 1.45,
            ease: 'power3.inOut',
            onUpdate: updateCamera
        });
        if (zoom) {
            gsap.to(orbit, {
                radius: compact ? 39 : 34,
                phi: 1.02,
                duration: reducedMotion ? 0.15 : 1.45,
                ease: 'power3.inOut',
                onUpdate: updateCamera
            });
        }
    }

    function coordinatesToCity(lat, lng) {
        var latitudeRadians = cityOrigin.latitude * Math.PI / 180;
        return {
            x: (lng - cityOrigin.longitude) * 111320 * Math.cos(latitudeRadians) / metersPerCityUnit,
            z: (cityOrigin.latitude - lat) * 111320 / metersPerCityUnit
        };
    }

    document.addEventListener('civicpulse:location-selected', function (event) {
        var point = coordinatesToCity(Number(event.detail.lat), Number(event.detail.lng));
        locationMarker.position.set(point.x, 0.08, point.z);
        locationMarker.visible = true;
        flyTo(point, true);
        gsap.fromTo(locationMarker.scale, { x: 0.35, y: 0.35, z: 0.35 }, {
            x: 1, y: 1, z: 1, duration: reducedMotion ? 0.12 : 0.55, ease: 'back.out(1.8)'
        });
    });

    document.addEventListener('civicpulse:category-selected', function (event) {
        var category = event.detail.category;
        var position = categoryPositions[category] || [0, 0];
        var color = categoryColors[category] || 0x65cde7;
        highlightRing.position.set(position[0], 0.22, position[1]);
        highlightAnchor.position.set(position[0], 0.36, position[1]);
        highlightRing.material.color.setHex(color);
        highlightAnchor.material.color.setHex(color);
        gsap.to(highlightRing.material, { opacity: 0.8, duration: 0.35 });
        gsap.to(highlightAnchor.material, { opacity: 0.85, duration: 0.35 });
        gsap.fromTo(highlightRing.scale, { x: 0.72, y: 0.72, z: 0.72 }, {
            x: 1.16, y: 1.16, z: 1.16, duration: 0.75, repeat: 1, yoyo: true, ease: 'sine.inOut'
        });
        flyTo(new THREE.Vector3(position[0], 0, position[1]), false);
    });

    resetViewButton.addEventListener('click', function () {
        gsap.to(cameraTarget, {
            x: 0, y: 0, z: 0,
            duration: reducedMotion ? 0.15 : 1.2,
            ease: 'power3.inOut',
            onUpdate: updateCamera
        });
        gsap.to(orbit, {
            theta: 0.78, phi: 1.12, radius: 54,
            duration: reducedMotion ? 0.15 : 1.2,
            ease: 'power3.inOut',
            onUpdate: updateCamera
        });
    });

    cityCanvas.addEventListener('pointerdown', function (event) {
        activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        dragging = true;
        panning = event.button === 2 || event.shiftKey || activePointers.size > 1;
        pointerTravelled = false;
        previousPointer.x = event.clientX;
        previousPointer.y = event.clientY;
        if (activePointers.size > 1) {
            var startPoints = Array.from(activePointers.values());
            previousPinchDistance = Math.hypot(
                startPoints[0].x - startPoints[1].x,
                startPoints[0].y - startPoints[1].y
            );
            previousPointer.x = startPoints.reduce(function (sum, point) { return sum + point.x; }, 0) / startPoints.length;
            previousPointer.y = startPoints.reduce(function (sum, point) { return sum + point.y; }, 0) / startPoints.length;
            pointerTravelled = true;
        }
        cityCanvas.setPointerCapture(event.pointerId);
    });
    cityCanvas.addEventListener('pointermove', function (event) {
        if (!dragging) {
            updateBuildingHover(event);
            return;
        }
        if (activePointers.has(event.pointerId)) {
            activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        }
        if (activePointers.size > 1) {
            var currentPoints = Array.from(activePointers.values());
            var pinchDistance = Math.hypot(
                currentPoints[0].x - currentPoints[1].x,
                currentPoints[0].y - currentPoints[1].y
            );
            if (previousPinchDistance > 0 && pinchDistance > 0) {
                orbit.radius = THREE.MathUtils.clamp(
                    orbit.radius * previousPinchDistance / pinchDistance,
                    30,
                    70
                );
            }
            previousPinchDistance = pinchDistance;
            var centerX = currentPoints.reduce(function (sum, point) { return sum + point.x; }, 0) / currentPoints.length;
            var centerY = currentPoints.reduce(function (sum, point) { return sum + point.y; }, 0) / currentPoints.length;
            var centerDeltaX = centerX - previousPointer.x;
            var centerDeltaY = centerY - previousPointer.y;
            var touchPanScale = orbit.radius / Math.max(cityCanvas.clientHeight, 1) * 1.8;
            var touchForward = new THREE.Vector3();
            camera.getWorldDirection(touchForward);
            touchForward.y = 0;
            touchForward.normalize();
            var touchRight = new THREE.Vector3(touchForward.z, 0, -touchForward.x).normalize();
            cameraTarget.addScaledVector(touchRight, -centerDeltaX * touchPanScale);
            cameraTarget.addScaledVector(touchForward, centerDeltaY * touchPanScale);
            previousPointer.x = centerX;
            previousPointer.y = centerY;
            pointerTravelled = true;
            panning = true;
            updateCamera();
            return;
        }
        if (Math.abs(event.clientX - previousPointer.x) + Math.abs(event.clientY - previousPointer.y) > 5) {
            pointerTravelled = true;
        }
        if (panning) {
            var panScale = orbit.radius / Math.max(cityCanvas.clientHeight, 1) * 1.8;
            var cameraForward = new THREE.Vector3();
            camera.getWorldDirection(cameraForward);
            cameraForward.y = 0;
            cameraForward.normalize();
            var cameraRight = new THREE.Vector3(cameraForward.z, 0, -cameraForward.x).normalize();
            cameraTarget.addScaledVector(cameraRight, -(event.clientX - previousPointer.x) * panScale);
            cameraTarget.addScaledVector(cameraForward, (event.clientY - previousPointer.y) * panScale);
            previousPointer.x = event.clientX;
            previousPointer.y = event.clientY;
            updateCamera();
            return;
        }
        orbit.theta -= (event.clientX - previousPointer.x) * 0.006;
        orbit.phi = THREE.MathUtils.clamp(orbit.phi + (event.clientY - previousPointer.y) * 0.004, 0.45, 1.42);
        previousPointer.x = event.clientX;
        previousPointer.y = event.clientY;
        updateCamera();
    });
    cityCanvas.addEventListener('pointerup', function (event) {
        activePointers.delete(event.pointerId);
        if (event.pointerType === 'touch' && activePointers.size > 0) {
            var remainingPointer = Array.from(activePointers.values())[0];
            previousPointer.x = remainingPointer.x;
            previousPointer.y = remainingPointer.y;
            previousPinchDistance = 0;
            dragging = true;
            panning = false;
            pointerTravelled = true;
            return;
        }
        previousPinchDistance = 0;
        if (!dragging) return;
        dragging = false;
        if (!panning && !pointerTravelled) updateBuildingHover(event);
        if (!panning && !pointerTravelled && hoveredBuilding) {
            flyTo(hoveredBuilding.point.clone(), true);
        }
        panning = false;
    });
    cityCanvas.addEventListener('pointercancel', function () {
        activePointers.clear();
        previousPinchDistance = 0;
        dragging = false;
        panning = false;
    });
    cityCanvas.addEventListener('contextmenu', function (event) { event.preventDefault(); });
    cityCanvas.addEventListener('keydown', function (event) {
        var directions = {
            ArrowUp: [0, -1],
            ArrowDown: [0, 1],
            ArrowLeft: [-1, 0],
            ArrowRight: [1, 0],
            w: [0, -1],
            s: [0, 1],
            a: [-1, 0],
            d: [1, 0]
        };
        var direction = directions[event.key] || directions[event.key.toLowerCase()];
        if (!direction) return;
        event.preventDefault();
        var travelDistance = compact ? 8 : 12;
        var cameraForward = new THREE.Vector3();
        camera.getWorldDirection(cameraForward);
        cameraForward.y = 0;
        cameraForward.normalize();
        var cameraRight = new THREE.Vector3(cameraForward.z, 0, -cameraForward.x).normalize();
        cameraTarget.addScaledVector(cameraRight, direction[0] * travelDistance);
        cameraTarget.addScaledVector(cameraForward, direction[1] * travelDistance);
        updateCamera();
    });
    cityCanvas.addEventListener('wheel', function (event) {
        event.preventDefault();
        orbit.radius = THREE.MathUtils.clamp(orbit.radius + event.deltaY * 0.022, 30, 70);
        updateCamera();
    }, { passive: false });

    var initialCityState = window.civicPulseCityState || {};
    if (initialCityState.category) {
        document.dispatchEvent(new CustomEvent('civicpulse:category-selected', {
            detail: { category: initialCityState.category }
        }));
    }
    if (Number.isFinite(Number(initialCityState.lat)) && Number.isFinite(Number(initialCityState.lng)) &&
        initialCityState.lat !== '' && initialCityState.lng !== '') {
        document.dispatchEvent(new CustomEvent('civicpulse:location-selected', {
            detail: { lat: Number(initialCityState.lat), lng: Number(initialCityState.lng) }
        }));
    }

    var lastFrame = 0;
    var frameInterval = compact ? 1000 / 30 : 1000 / 45;
    var sceneVisible = true;
    if ('IntersectionObserver' in window) {
        var sceneObserver = new IntersectionObserver(function (entries) {
            sceneVisible = entries[0].isIntersecting;
        });
        sceneObserver.observe(host);
    }
    function animate(timestamp) {
        window.requestAnimationFrame(animate);
        if (document.hidden || !sceneVisible) return;
        if (timestamp - lastFrame < frameInterval) return;
        var delta = Math.min((timestamp - (lastFrame || timestamp)) / 1000, 0.05);
        lastFrame = timestamp;
        idleClock += delta;
        if (!dragging && !reducedMotion) {
            orbit.theta += delta * 0.012;
            updateCamera();
        }
        if (!reducedMotion) {
            train.position.x = -23 + ((idleClock * 3.1) % 46);
            metroTrains.forEach(function (metro) {
                metro.mesh.position.x = -24 + ((idleClock * 3.1 + metro.phase) % cityChunkSize);
            });
            trafficCars.forEach(function (car) {
                car.offset += car.speed * delta;
                if (car.offset > car.max) car.offset = car.min;
                if (car.offset < car.min) car.offset = car.max;
                if (car.axis === 'x') {
                    car.mesh.position.x = car.offset;
                    car.mesh.position.z = car.lane;
                } else {
                    car.mesh.position.z = car.offset;
                    car.mesh.position.x = car.lane;
                }
            });
            droneGroups.forEach(function (drone, index) {
                var wave = idleClock * drone.speed + drone.phase;
                drone.mesh.position.x = drone.x + Math.sin(wave) * 3.8;
                drone.mesh.position.y = (index ? 12 : 10) + Math.sin(wave * 1.6) * 0.48;
                drone.mesh.position.z = drone.z + Math.cos(wave * 0.72) * 2.7;
                drone.mesh.rotation.z = Math.sin(wave) * 0.06;
                drone.mesh.rotation.x = Math.cos(wave) * 0.045;
            });
            particles.rotation.y = idleClock * 0.008;
        }
        if (locationMarker.visible) {
            var pulse = reducedMotion ? 1 : 1 + Math.sin(idleClock * 2.6) * 0.13;
            locationRing.scale.set(pulse, pulse, pulse);
        }
        renderer.render(scene, camera);
    }
    sceneLabel.textContent = 'Endless city · streamed districts · traffic + metro';
    window.requestAnimationFrame(animate);

    function resizeCity() {
        var nextWidth = host.clientWidth;
        var nextHeight = host.clientHeight;
        if (!nextWidth || !nextHeight) return;
        camera.aspect = nextWidth / nextHeight;
        camera.updateProjectionMatrix();
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, compact ? 1.15 : 1.6));
        renderer.setSize(nextWidth, nextHeight, false);
    }
    if ('ResizeObserver' in window) {
        var resizeObserver = new ResizeObserver(resizeCity);
        resizeObserver.observe(host);
    } else {
        window.addEventListener('resize', resizeCity);
    }
}

startCivicPulseCity();
