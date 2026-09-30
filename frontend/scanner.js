// Serialize camera operations: closing during a permission prompt or startup
// must still release the camera if that pending start eventually succeeds.
export function createScanner({ elementId, onScan, onError, onState, onCameras = () => {}, onProgress = () => {}, loadLibrary = () => import('html5-qrcode') }) {
  let generation = 0;
  let operation = Promise.resolve();
  let scanner = null;

  async function release() {
    const instance = scanner;
    scanner = null;
    if (instance) {
      try { if (instance.isScanning) await instance.stop(); }
      finally { instance.clear(); }
    }
  }

  function stop() {
    ++generation;
    operation = operation.catch(() => {}).then(release).catch(() => {
      // Defensive fallback if the library rejects during teardown.
      const video = document.getElementById(elementId)?.querySelector('video');
      video?.srcObject?.getTracks().forEach(track => track.stop());
    }).then(() => onState('idle'));
    return operation;
  }

  function start(deviceId = '') {
    const current = ++generation;
    onState('starting');
    operation = operation.catch(() => {}).then(async () => {
      await release();
      if (current !== generation) return;
      try {
        const { Html5Qrcode, Html5QrcodeSupportedFormats: formats } = await loadLibrary();
        if (current !== generation) return;
        scanner = new Html5Qrcode(elementId, {
          formatsToSupport: [formats.EAN_13, formats.EAN_8, formats.UPC_A],
          // Use the bundled decoder consistently across browsers. Native
          // BarcodeDetector support does not guarantee support for UPC/EAN.
          useBarCodeDetectorIfSupported: false,
          verbose: false,
        });
        let detected = false;
        let misses = 0;
        const camera = deviceId || { facingMode: 'environment' };
        await scanner.start(camera, {
          fps: 10,
          disableFlip: true,
          videoConstraints: {
            ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }),
            width: { ideal: 1920 }, height: { ideal: 1080 },
          },
          qrbox: (width, height) => ({ width: Math.floor(width * 0.9), height: Math.floor(Math.min(height * 0.7, 160)) }),
        }, text => {
          if (current !== generation || detected) return;
          detected = true;
          void stop().then(() => onScan(text));
        }, () => {
          if (current === generation && ++misses % 30 === 0) onProgress(misses);
        });
        if (current !== generation) return;
        onState('running');
        // Camera enumeration happens only after permission was granted.
        try {
          const devices = await navigator.mediaDevices.enumerateDevices();
          if (current === generation) onCameras(devices.filter(device => device.kind === 'videoinput'), scanner.getRunningTrackSettings().deviceId);
        } catch { /* Camera switching is optional; scanning still works. */ }
      } catch {
        await release();
        if (current === generation) {
          onState('idle');
          onError('Camera unavailable. Allow camera access in your browser, or enter the barcode below.');
        }
      }
    });
    return operation;
  }
  return { start, stop };
}
