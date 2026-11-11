// The cashier brand colors. All 8 styles share them, and the values live in schemes.json so the
// Blade layout reads the same ones (PosSettingsController::cssVariables). Keys must match 'schemes' in config/pos.php.
import schemes from './schemes.json';

export const SCHEMES = schemes;
