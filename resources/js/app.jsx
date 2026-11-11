import './bootstrap';
import React from 'react';
import { createRoot } from 'react-dom/client';
import Pos from './components/Pos';
import Receiving from './components/Receiving';
import StockCount from './components/StockCount';
import BarcodeLabels from './components/BarcodeLabels';
import Dashboard from './components/Dashboard';
import Products from './components/Products';
import Sales from './components/Sales';
import PosSettings from './components/PosSettings';
import ScannerTest from './components/ScannerTest';
import BatchAdd from './components/BatchAdd';
import ProductBarcodes from './components/ProductBarcodes';
import BasketItems from './components/BasketItems';
import { startTableCards } from './tableCards';

// Blade pages mount React screens with <div data-react="Pos" data-props='{...}'>
const screens = { Pos, Receiving, StockCount, BarcodeLabels, Dashboard, Products, Sales, PosSettings, ScannerTest, BatchAdd, ProductBarcodes, BasketItems };

document.querySelectorAll('[data-react]').forEach((el) => {
    const Screen = screens[el.dataset.react];
    if (!Screen) return;
    const props = el.dataset.props ? JSON.parse(el.dataset.props) : {};
    createRoot(el).render(<Screen {...props} />);
});

startTableCards();
