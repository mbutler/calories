import { test, expect } from 'bun:test';
import { ZXingHtml5QrcodeDecoder } from 'html5-qrcode/esm/zxing-html5-qrcode-decoder.js';
import { Html5QrcodeSupportedFormats as formats } from 'html5-qrcode/esm/core.js';

// Render standard EAN bars into RGBA pixels and feed the real bundled decoder.
// These tests complement the mocked camera lifecycle tests.
const left = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
const alternate = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
const parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
function image(code) {
  let bits = '000000000000101';
  const half = code.length === 8 ? 4 : 6;
  const start = code.length === 8 ? 0 : 1;
  for (let i = 0; i < half; i++) bits += (start && parity[Number(code[0])][i] === 'G' ? alternate : left)[Number(code[start + i])];
  bits += '01010';
  for (let i = start + half; i < code.length; i++) bits += left[Number(code[i])].replace(/[01]/g, bit => bit === '1' ? '0' : '1');
  bits += '101000000000000';
  const width = bits.length * 2, height = 140;
  const data = new Uint8ClampedArray(width * height * 4).fill(255);
  for (let y = 15; y < height - 15; y++) {
    for (let x = 0; x < width; x++) {
      if (bits[Math.floor(x / 2)] === '1') {
        const offset = (y * width + x) * 4;
        data[offset] = data[offset + 1] = data[offset + 2] = 0;
      }
    }
  }
  return { width, height, getContext: () => ({ getImageData: () => ({ data }) }) };
}
for (const [name, code, expected] of [
  ['EAN-13', '3017624010701', '3017624010701'],
  ['UPC-A', '0012345678905', '012345678905'],
  ['EAN-8', '96385074', '96385074'],
]) {
  test(`real decoder reads ${name} pixels at phone preview resolution`, async () => {
    const decoder = new ZXingHtml5QrcodeDecoder([formats.EAN_13, formats.EAN_8, formats.UPC_A], false, { logError() {} });
    expect((await decoder.decodeAsync(image(code))).text).toBe(expected);
  });
}
