import React, { useEffect } from 'react';
import { usePos } from './pos/usePos';
import { DoneDialog, PayDialog, VoidApprovalDialog, WeightDialog } from './pos/Dialogs';
import { getScheme, themeClass, themeStyle } from './pos/theme';
import ScanPro from './pos/styles/ScanPro';
import CafeTiles from './pos/styles/CafeTiles';
import TableService from './pos/styles/TableService';
import Kiosk from './pos/styles/Kiosk';
import Boutique from './pos/styles/Boutique';
import Booking from './pos/styles/Booking';
import Pocket from './pos/styles/Pocket';
import CounterClassic from './pos/styles/CounterClassic';

// Keys match config/pos.php 'styles'
export const STYLES = { scanpro: ScanPro, cafe: CafeTiles, table: TableService, kiosk: Kiosk, boutique: Boutique, booking: Booking, pocket: Pocket, counter: CounterClassic };
// Each design names its own pay key
const PAY_KEYS = { scanpro: 'F12', counter: 'F10' };

/** The cashier screen. The owner picks the style and color scheme under Setup → POS style. */
export default function Pos({ cashier, isSupervisor, storeName, style = 'scanpro', styleName, scheme, preview, settingsUrl }) {
    const pos = usePos({ payKey: PAY_KEYS[style] || 'F8', supervisor: isSupervisor });
    const Layout = STYLES[style] || ScanPro;
    const k = getScheme(scheme);
    const colors = themeStyle(k);

    // Opening or closing the menu hands the cursor back to the scan box
    useEffect(() => {
        window.addEventListener('pos-nav-changed', pos.focusScan);
        return () => window.removeEventListener('pos-nav-changed', pos.focusScan);
    }, [pos.focusScan]);

    return (
        <div className={`${themeClass} flex h-full flex-col`} style={colors}>
            {preview && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-dashed border-brand-500 bg-brand-50 px-4 py-2 text-sm">
                    <span>Preview: <b>{styleName}</b> · {k.name}. Sales made here are real.</span>
                    <a href={settingsUrl} className="font-semibold text-brand-700 underline">Back to POS style settings</a>
                </div>
            )}
            <div className="min-h-0 flex-1">
                <Layout pos={pos} cashier={cashier} storeName={storeName} />
            </div>

            {pos.weighing && (
                <WeightDialog product={pos.weighing} onCancel={() => { pos.setWeighing(null); pos.focusScan(); }}
                    onConfirm={(kg) => { pos.addToCart(pos.weighing, kg); pos.setWeighing(null); pos.focusScan(); }} />
            )}
            {pos.voidRequest && <VoidApprovalDialog request={pos.voidRequest} onApproved={pos.approveVoid} onCancel={pos.cancelVoid} />}
            {pos.paying && (
                <PayDialog subtotal={pos.subtotal} cart={pos.cart} storeName={storeName} cashier={cashier}
                    onCancel={() => { pos.setPaying(false); pos.focusScan(); }}
                    onDone={(result) => { pos.setPaying(false); pos.setDone(result); }} />
            )}
            {pos.done && <DoneDialog result={pos.done} onNext={pos.newSale} onScan={(code) => { pos.newSale(); pos.onScan(code); }} />}
        </div>
    );
}
