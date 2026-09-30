import { describe, test, expect } from 'bun:test';
import { createScanner } from '../frontend/scanner.js';

function setup({ pending = false, fail = false } = {}) {
  let finish;
  const calls = [];
  const scans = [];
  const errors = [];
  let success;
  class FakeScanner {
    isScanning = false;
    async start(_camera, _config, callback) {
      calls.push('start'); success = callback;
      if (pending) await new Promise(resolve => { finish = resolve; });
      if (fail) throw new Error('Permission denied');
      this.isScanning = true;
    }
    async stop() { calls.push('stop'); this.isScanning = false; }
    clear() { calls.push('clear'); }
  }
  const camera = createScanner({ elementId: 'unused', onScan: code => scans.push(code), onError: error => errors.push(error), onState: () => {},
    loadLibrary: async () => ({ Html5Qrcode: FakeScanner, Html5QrcodeSupportedFormats: { EAN_13: 1, EAN_8: 2, UPC_A: 3 } }) });
  return { camera, calls, scans, errors, finish: () => finish(), detect: code => success(code) };
}
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
describe('barcode camera lifecycle', () => {
  test('stops the camera after a detection and ignores duplicate frames', async () => {
    const fixture = setup();
    await fixture.camera.start();
    fixture.detect('012345678905'); fixture.detect('012345678905');
    await tick();
    expect(fixture.scans).toEqual(['012345678905']);
    expect(fixture.calls).toEqual(['start', 'stop', 'clear']);
  });
  test('closing while permissions are pending releases the eventual stream', async () => {
    const fixture = setup({ pending: true });
    const starting = fixture.camera.start();
    await tick();
    const stopping = fixture.camera.stop();
    fixture.finish();
    await Promise.all([starting, stopping]);
    expect(fixture.calls).toEqual(['start', 'stop', 'clear']);
    fixture.detect('012345678905');
    expect(fixture.scans).toEqual([]);
  });
  test('permission denial leaves manual entry available', async () => {
    const fixture = setup({ fail: true });
    await fixture.camera.start();
    expect(fixture.errors).toHaveLength(1);
    expect(fixture.calls).toEqual(['start', 'clear']);
  });
  test('can reopen without accumulating active scanners', async () => {
    const fixture = setup();
    await fixture.camera.start(); await fixture.camera.stop();
    await fixture.camera.start(); await fixture.camera.stop();
    expect(fixture.calls).toEqual(['start', 'stop', 'clear', 'start', 'stop', 'clear']);
  });
  test('switching cameras releases the previous stream before starting another', async () => {
    const fixture = setup();
    await fixture.camera.start();
    await fixture.camera.start('another-camera');
    await fixture.camera.stop();
    expect(fixture.calls).toEqual(['start', 'stop', 'clear', 'start', 'stop', 'clear']);
  });
});
