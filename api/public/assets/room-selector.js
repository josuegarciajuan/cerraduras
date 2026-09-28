/**
 * room-selector.js — lógica PURA de decisión de reconstrucción del selector de
 * habitación del dashboard (`#room-selector`).
 *
 * Motivo: `fetchRooms()` corre cada 5 s. Reconstruir los `<option>` (innerHTML)
 * mientras el usuario tiene abierto el picker nativo lo cierra — o impide que
 * llegue a abrirse — en Firefox/Safari/Android. La decisión de tocar el DOM se
 * aísla aquí para poder testearla sin navegador (ver tests/Unit/room-selector.test.js).
 *
 * UMD: global `RoomSelector` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.RoomSelector = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  /**
   * Decide si hay que aplicar cambios al DOM del selector de habitación.
   *
   * Reglas:
   *  - Sin cambios (firma igual y nº de opciones igual) → no tocar el DOM.
   *  - Con cambios pero el usuario está interactuando (foco o ventana de
   *    interacción activa) → NO tocar el DOM y reintentar en el siguiente tick
   *    (devolver `blocked`). Así el picker nativo nunca se cierra solo.
   *  - Con cambios y sin interacción → reconstruir/actualizar.
   *
   * @param {Object} input
   * @param {string|null} input.prevSignature  firma ya aplicada al DOM (o null)
   * @param {string}      input.nextSignature  firma de los datos recién recibidos
   * @param {number}      input.optionCount    nº de <option> actuales en el DOM
   * @param {number}      input.roomCount      nº de habitaciones recibidas
   * @param {boolean}     input.focused        document.activeElement === select
   * @param {number}      input.interactingUntil epoch ms hasta el que no tocar el DOM
   * @param {number}      input.now            epoch ms actual
   * @returns {{rebuild:boolean, blocked:boolean}}
   */
  function shouldRebuildRoomOptions(input) {
    input = input || {};
    var prevSignature = (input.prevSignature == null) ? null : String(input.prevSignature);
    var nextSignature = (input.nextSignature == null) ? null : String(input.nextSignature);
    var optionCount = Number(input.optionCount) || 0;
    var roomCount = Number(input.roomCount) || 0;

    var idsChanged = optionCount !== roomCount;
    var signatureChanged = prevSignature !== nextSignature;
    if (!idsChanged && !signatureChanged) {
      return { rebuild: false, blocked: false };
    }

    var focused = !!input.focused;
    var interactingUntil = Number(input.interactingUntil) || 0;
    var now = Number(input.now) || 0;
    if (focused || interactingUntil > now) {
      return { rebuild: false, blocked: true };
    }

    return { rebuild: true, blocked: false };
  }

  return {
    shouldRebuildRoomOptions: shouldRebuildRoomOptions
  };
});
