<?php
require_once('all.php');

class CalculationsPortfolioAROR {

    public static function calculateReturnsTaxTypeInternational($Portfolio, $portfolio_total_value, $portfolio_lifetime_dividends){
        // 1. Prep
        $User = User::validateRequiredUserLogin();
        if ($User instanceof ErrorLog) {
            return $User;
        }
        if (empty($portfolio_total_value)) {
            return $arr;
        }
        $EntryObjects = Portfolio::getEntryCashObjects($Portfolio);
        if ($EntryObjects instanceof ErrorLog) {
            return $EntryObjects;
        }
        if (empty($EntryObjects)) {
            return $arr;
        }
        $first_transaction_date = CalculationsPortfolioAROR::getFirstTransactionDate($EntryObjects);
        if ($first_transaction_date instanceof ErrorLog) {
            return $first_transaction_date;
        }
        // 2. Calculate AROR
        $fifo_buy_table = [];
        $sales_table = [];
        $TIMEZONE = NFTime::ensureValidTimezone('UTC');
        $Today = new DateTime("now", new DateTimeZone($TIMEZONE));
        $fifo_buy_pointer = -1;
        $total_deposits_usd = 0;
        foreach ($EntryObjects as $date => $sub_arr) { // EntryObjects is ordered in logical buy-sell order
            foreach ($sub_arr as $EntryObject) {
                if (($EntryObject instanceof EntryCash)) {
                    if ($EntryObject->getTransactionType() == 'deposit') {
                        $fifo_buy_pointer++;
                        $temp = [];
                        $temp['buy_entry_id'] = $EntryObject->getEntryId();
                        $temp['cash_amount_usd'] = $EntryObject->getCashAmountUSD();
                        $temp['buy_date_ymd'] =  $EntryObject->getEntryDateTimeObjectYMD($TIMEZONE);
                        $fifo_buy_table[$fifo_buy_pointer] = $temp;
                        $total_deposits_usd = $total_deposits_usd + $temp['cash_amount_usd'];
                    } else {
                        $sales_table = CalculationsPortfolioAROR::addFIFOSalesPairsToTable($fifo_buy_table, $EntryObject, $sales_table, $TIMEZONE);
                        if ($sales_table instanceof ErrorLog) {
                            return $sales_table;
                        }
                        $total_deposits_usd = $total_deposits_usd - $temp['cash_amount_usd'];
                    }
                }
            }
        }
        $portfolio_total_value = $portfolio_total_value + $portfolio_lifetime_dividends;
        $returns = CalculationsPortfolioAROR::calculateARORAndTotalReturn($User, $EntryObjects, $fifo_buy_table, $sales_table, $portfolio_total_value, $Today, $TIMEZONE);
        if (!($returns instanceof ErrorLog)) {
            $arr['return_total_usd'] = floatval($returns['return_total_usd']);
            $arr['return_total_percent'] = floatval($returns['return_total_percent']);
            $arr['return_annualized'] = floatval($returns['weighted_aror']);
            $arr['total_deposits_usd'] = floatval($total_deposits_usd);
            if ($arr['return_annualized'] >= 0) {
                $aror_string = '<span class="green">'.number_format($arr['return_annualized'], 2).'%</span>';
            } else {
                $aror_string = '<span class="brandred">'.number_format($arr['return_annualized'], 2).'%</span>';
            }
            $arr['aror_return_percent_usd_string'] = $aror_string;
            if ($arr['total_deposits_usd'] >= 0) {
                $total_deposits_usd_string = '<span class="grey">$'.number_format($arr['total_deposits_usd'], 0).'</span>';
            } else {
                $total_deposits_usd_string = '<span class="lgrey">...</span>';
            }
            $arr['total_deposits_usd_string'] = $total_deposits_usd_string;
        } 
        return $arr;
    }















    /* private t1 */






    

    private static function calculateARORAndTotalReturn($User, $EntryObjects, $fifo_buy_table, $sales_table, $portfolio_total_value, $Today, $TIMEZONE){
        $lots_table = CalculationsPortfolioAROR::compileLotsTable($fifo_buy_table, $sales_table, $Today, $TIMEZONE);
        $adjust_for_fees_and_calculate_total_investment_table = CalculationsPortfolioAROR::addFeesAndTotalInvestmentAndTotalReturnToReturnsTable($User, $EntryObjects, $lots_table, $portfolio_total_value);
        // --> add ratios
            // -> get totals table
            // -> pass totals table per row
        $adjust_for_fees_and_calculate_total_investment_table = CalculationsPortfolioAROR::addValueBasedOnRatioCalculationsToAllLots($adjust_for_fees_and_calculate_total_investment_table, $EntryObjects, $portfolio_total_value);
        $calculations_table = CalculationsPortfolioAROR::calculateARORForEachLot($adjust_for_fees_and_calculate_total_investment_table, $Today, $TIMEZONE);
        $weighted_aror = CalculationsPortfolioAROR::calculateWeightedAror($calculations_table);
        $returns = [];
        $returns['return_total_usd'] = $calculations_table['return_total_usd'];
        $returns['return_total_percent'] = $calculations_table['return_total_percent'];
        $returns['weighted_aror'] = $weighted_aror;
        return $returns;
    }

    private static function addFIFOSalesPairsToTable($fifo_buy_table, $StockSaleEntry, $sales_table, $TIMEZONE){
        $usd_to_withdraw = $StockSaleEntry->getCashAmountUSD() * -1;
        $sale_date_ymd = $StockSaleEntry->getEntryDateTimeObjectYMD($TIMEZONE);
        if ($sale_date_ymd instanceof ErrorLog) {
            return $sale_date_ymd;
        }
        $fifo_buy_key = 0;
        $i = 0;
        while ($usd_to_withdraw > 0) {
            $max_subtractible_usd = CalculationsPortfolioAROR::getMaxSubtractableUSDBetweenSaleDateAndCurrentBuyEntry($sale_date_ymd, $fifo_buy_table[$fifo_buy_key], $usd_to_withdraw, $sales_table);
            if ($i > 100) {
                die('@@@');
                break;
            }
            if ($max_subtractible_usd <= 0) { // sold all shares from first buy lot, need to move FIFO_buy_key up the queue.
                $i++;
                $fifo_buy_key++;
                continue;
            }
            $sales_table = CalculationsPortfolioAROR::insertRowToSalesTable($sales_table, $sale_date_ymd, $max_subtractible_usd, $fifo_buy_table[$fifo_buy_key]);
            $usd_to_withdraw = $usd_to_withdraw - $max_subtractible_usd;
            $i++;
        }
        return $sales_table;
    }

    private static function getFirstTransactionDate($EntryObjects){
        foreach ($EntryObjects as $date => $sub_arr) {
            foreach ($sub_arr as $EntryObject) {
                if (($EntryObject instanceof EntryCash)) {
                    return $EntryObject->getEntryDateUTC();
                }
            }
        }
    }















    /* private t2 */







    

    

    private static function getMaxSubtractableUSDBetweenSaleDateAndCurrentBuyEntry($sale_date_ymd, $fifo_buy_entry, $usd_to_withdraw, $sales_table){
        $shares_available_from_current_fifo_buy_on_this_date = $fifo_buy_entry['cash_amount_usd'];
        $num_shares_pledged = CalculationsPortfolioAROR::getUSDAlreadyPledgedToOtherSalesForThisEntryBeforeOrOnThisSellDate($sale_date_ymd, $fifo_buy_entry['buy_entry_id'], $sales_table);
        $num_shares_available_for_sale_lot = $shares_available_from_current_fifo_buy_on_this_date - $num_shares_pledged;
        if ($usd_to_withdraw <= $num_shares_available_for_sale_lot) {
            return $usd_to_withdraw;
        } else {
            return $num_shares_available_for_sale_lot;
        }
        return $num_shares_available_for_sale_lot;
    }

    private static function insertRowToSalesTable($sales_table, $sale_date_ymd, $max_subtractible_usd, $buy_lot_being_sold_from){
        $sale = [];
        $sale['sold_from_buy_lot_entry_id'] = $buy_lot_being_sold_from['buy_entry_id'];
        $sale['buy_date_ymd'] = $buy_lot_being_sold_from['buy_date_ymd'];
        $sale['sell_date_ymd'] = $sale_date_ymd;
        $sale['usd_amount_sold'] = $max_subtractible_usd;
        $sale['total_proceeds'] = $max_subtractible_usd;
        $sales_table[$buy_lot_being_sold_from['buy_entry_id']][] = $sale;
        return $sales_table;
    }

    private static function addFeesAndTotalInvestmentAndTotalReturnToReturnsTable($User, $EntryObjects, $returns_table, $portfolio_total_value){
        if (empty($returns_table['total_dividends'])) {
            $returns_table['total_dividends'] = 0;
        }
        $totals_table = Entry::getTotalsForEntryCashObjects($EntryObjects);
        $total_investment_including_fees = $totals_table['total_cost_basis'] + $totals_table['total_fees'];
        $total_returns = CalculationsPortfolioAROR::calculateNetReturns($totals_table);
        // 5. Done
        $final_table = [];
        $final_table['lots'] = $returns_table['lots'];
        $final_table['total_investment_including_fees'] = $total_investment_including_fees;
        $final_table['return_total_percent'] = $total_returns['return_total_percent'];
        $final_table['return_total_usd'] = $total_returns['return_total_usd'];
        return $final_table;
    }

    private static function calculateARORForEachLot($table, $Today, $TIMEZONE){
        $lots = $table['lots'];
        $i = 0;
        foreach ($lots as $lot) {
            $gain = $lot['portfolio_value_relative_to_investment_weight'] - $lot['investment'];
            if ($lot['sell_date']) {
                $SaleDateTime = new DateTime($lot['sell_date'], new DateTimeZone($TIMEZONE));
            } else {
                $SaleDateTime = $Today;
            }
            $days_invested = CalculationsPortfolioAROR::calculateInitialDaysInvested($lot['buy_date'], $SaleDateTime, $TIMEZONE);
            if ($days_invested == 0) {
                $table['lots'][$i]['aror'] = 'n/a';
                return $table;
            }
            $step1 = $lot['investment'] + $gain;
            $step2 = $lot['investment'];
            $step3 = $step1/$step2;
            $step4 = $step3**(365/$days_invested)-1;
            $step5 = number_format($step4*100, 2);
            $aror = $step5;
            $table['lots'][$i]['aror'] = $aror;
            $i++;
        }
        return $table;
    }

    private static function calculateWeightedAror($final_table){
        $lots = $final_table['lots'];
        $total_investment_including_fees = $final_table['total_investment_including_fees'];
        $t = 0;
        $total_weighted_aror = 0;
        foreach ($lots as $lot) {
            $weighting = $lot['investment']/$total_investment_including_fees;
            if (!is_numeric($lot['aror'])) {
                $lot['aror'] = intval($lot['aror']); // fixes odd !numeric bug
            }
            $weighted_aror = $lot['aror'] * $weighting;
            $total_weighted_aror = $total_weighted_aror + $weighted_aror;
        }
        return $total_weighted_aror;
    }

    private static function compileLotsTable($fifo_buy_table, $sales_table, $Today, $TIMEZONE){
        $lots_table = [];
        foreach ($fifo_buy_table as $buy_lot) {
            if (!empty($sales_table[$buy_lot['buy_entry_id']])) {
                $lots_table = CalculationsPortfolioAROR::returnsTableGetRowsForRemainingSharesFromBuyEntryWithOneOrMoreSaleLotsAssociatedWithIt($lots_table, $buy_lot, $sales_table[$buy_lot['buy_entry_id']], $Today, $TIMEZONE);
                $lots_table = CalculationsPortfolioAROR::returnsTableGetAllRowsForAllSalesForGivenBuyLot($lots_table, $sales_table[$buy_lot['buy_entry_id']], $TIMEZONE); // if false returns return_table;
            } else {
                // buy lot with no sales associated with it
                $lots_table = CalculationsPortfolioAROR::returnsTableGetRowForBuyLotThatHasNoFIFOSalesAssociatedWithIt($lots_table, $buy_lot, $Today, $TIMEZONE);
            }
        }
        $formatted_table = [];
        $formatted_table['lots'] = $lots_table;
        return $formatted_table;
    }















    /* private t3 */






    

    private static function getUSDAlreadyPledgedToOtherSalesForThisEntryBeforeOrOnThisSellDate($sale_date_ymd, $buy_entry_id, $sales_table){
        $pledged = 0;
        if (isset($sales_table[$buy_entry_id])) {
            foreach ($sales_table[$buy_entry_id] as $stock_sale) {
                if (strtotime($stock_sale['sell_date_ymd']) <= strtotime($sale_date_ymd)) { // is this necessary for fifo..?
                    $pledged = $pledged + $stock_sale['usd_amount_sold'];
                }
            } 
        }
        return $pledged;
    }

    private static function calculateNetReturns($totals_table){
        $total_return_percent = 0;
        $total_return_usd = 0;
        $total_investment = $totals_table['total_cost_basis'] + $totals_table['total_fees'];
        $current_equity_plus_all_dividends_received = $total_investment;
        $total_return_usd = $current_equity_plus_all_dividends_received - $total_investment;
        if ($current_equity_plus_all_dividends_received >= $total_investment) {
            $total_return_percent = $current_equity_plus_all_dividends_received / $total_investment * 100;
        } else {
            $amount_lost = $total_investment - $current_equity_plus_all_dividends_received;
            $percent_lost = $amount_lost/$total_investment * 100;
            $total_return_percent = $percent_lost * (-1);
        }
        $total_returns['total_investment'] = $total_investment;
        $total_returns['total_dividends'] = 0;
        $total_returns['current_equity_plus_all_dividends_received'] = $current_equity_plus_all_dividends_received;
        $total_returns['return_total_percent'] = $total_return_percent;
        $total_returns['return_total_usd'] = $total_return_usd;
        return $total_returns;
    }

    private static function calculateInitialDaysInvested($bot_ymd, $TodayDatetime, $TIMEZONE){
        $BuyDatetime = new DateTime($bot_ymd, new DateTimeZone($TIMEZONE));
        $diff = $TodayDatetime->diff($BuyDatetime)->format("%a");
        return $diff;
    }

    private static function returnsTableGetRowsForRemainingSharesFromBuyEntryWithOneOrMoreSaleLotsAssociatedWithIt($returns_table, $buy_lot, $sales_lot_arr, $Today, $TIMEZONE){
        $num_buy_lot_shares_owned_today = CalculationsPortfolioAROR::returnsTableGetNumberOfSharesStillOwnedTodayFromBuyLotThatHasAssociatedSalesLots($buy_lot, $sales_lot_arr);
        $split_adjusted_price_paid_per_share_today = CalculationsPortfolioAROR::returnsTableGetSplitAdjustedPricePerSharePaidForSharesStillOwnedTodayFromBuyLotThatHasAssociatedSalesLots($buy_lot, $sales_lot_arr);
        $spoof_buy_lot = CalculationsPortfolioAROR::returnsTableGetSpoofedBuyLotForCurSharesOwned($num_buy_lot_shares_owned_today, $split_adjusted_price_paid_per_share_today, $buy_lot);
        $returns_table = CalculationsPortfolioAROR::returnsTableGetRowForSpoofBuyLotSharesOwnedToday($returns_table, $spoof_buy_lot, $Today, $TIMEZONE); // if false returns return_table;
        return $returns_table;
    }

    private static function returnsTableGetAllRowsForAllSalesForGivenBuyLot($returns_table, $sales_lot_arr, $TIMEZONE){
        if ($sales_lot_arr == false) {
            return $returns_table;
        }
        foreach ($sales_lot_arr as $sale_lot) {
            $row = [];
            $row['buy_date'] = $sale_lot['buy_date_ymd'];
            $row['sell_date'] = $sale_lot['sell_date_ymd'];
            $row['investment'] = $sale_lot['usd_amount_sold'];
            $returns_table[] = $row;
        }
        return $returns_table;
    }

    private static function returnsTableGetRowForBuyLotThatHasNoFIFOSalesAssociatedWithIt($returns_table, $buy_lot, $Today, $TIMEZONE){
        if ($buy_lot == false) {
            return $returns_table;
        }
        $row = [];
        $row['buy_date'] = $buy_lot['buy_date_ymd'];
        $row['sell_date'] = false;
        $row['investment'] = $buy_lot['cash_amount_usd'];
        $returns_table[] = $row;
        return $returns_table;

    }

    private static function returnsTableGetNumberOfSharesStillOwnedTodayFromBuyLotThatHasAssociatedSalesLots($buy_lot, $sales_lot_arr){
        $num_shares_owned_before_sales = $buy_lot['cash_amount_usd'];
        $num_shares_owned_during_sales = $num_shares_owned_before_sales;
        $buy_ymd = $buy_lot['buy_date_ymd'];
        foreach ($sales_lot_arr as $sales_lot) {
            $buy_ymd = $sales_lot['sell_date_ymd'];
            $num_shares_owned_during_sales = $num_shares_owned_during_sales - $sales_lot['usd_amount_sold'];
        }
        return $num_shares_owned_during_sales;
    }















    /* private t4 */






    

    private static function returnsTableGetSplitAdjustedPricePerSharePaidForSharesStillOwnedTodayFromBuyLotThatHasAssociatedSalesLots($buy_lot, $sales_lot_arr){
        $price_per_share_before_splits = $buy_lot['cash_amount_usd'];
        $price_per_share_during_splits = $price_per_share_before_splits;
        $buy_ymd = $buy_lot['buy_date_ymd'];
        foreach ($sales_lot_arr as $sales_lot) {
            $buy_ymd = $sales_lot['sell_date_ymd'];
        }
        $today_ymd = date('Y-m-d');
        return $price_per_share_during_splits;
    }

    private static function returnsTableGetSpoofedBuyLotForCurSharesOwned($num_buy_lot_shares_owned_today, $split_adjusted_price_paid_per_share_today, $buy_lot){
        if (empty($num_buy_lot_shares_owned_today)) { // 0 shares owned today
            return false;
        }
        $spoof_buy_lot = $buy_lot;
        unset($spoof_buy_lot['num_shares_bot']); // not split adjusted
        unset($spoof_buy_lot['price_per_share']); // not split adjusted
        $spoof_buy_lot['split_adjusted_shares_owned_today'] = $num_buy_lot_shares_owned_today;
        $spoof_buy_lot['split_adjusted_total_investment'] = $split_adjusted_price_paid_per_share_today;
        return $spoof_buy_lot;
    }

    private static function addValueBasedOnRatioCalculationsToAllLots($adjust_for_fees_and_calculate_total_investment_table, $EntryObjects, $portfolio_total_value){
        $totals_table = Entry::getTotalsForEntryCashObjects($EntryObjects);
        $total_cost_basis = $totals_table['total_cost_basis'];
        foreach ($adjust_for_fees_and_calculate_total_investment_table['lots'] as $index => $lot) {
            $adjust_for_fees_and_calculate_total_investment_table['lots'][$index] = CalculationsPortfolioAROR::calculateAndAppendWeightedValueToLot($lot, $portfolio_total_value, $total_cost_basis);
        }
        return $adjust_for_fees_and_calculate_total_investment_table;
    }

    private static function returnsTableGetRowForSpoofBuyLotSharesOwnedToday($returns_table, $spoof_buy_lot, $Today, $TIMEZONE){
        if ($spoof_buy_lot == false) {
            return $returns_table;
        }
        $row = [];
        $row['buy_date'] = $spoof_buy_lot['buy_date_ymd'];
        $row['sell_date'] = false;
        $row['investment'] = $spoof_buy_lot['split_adjusted_shares_owned_today'];
        $returns_table[] = $row;
        return $returns_table;
    }















    /* private t5 */

    private static function calculateAndAppendWeightedValueToLot($lot, $portfolio_total_value, $total_cost_basis){
        $investment = $lot['investment'];
        $ratio_to_total_investment = $investment/$total_cost_basis;
        $portfolio_value_relative_to_investment_weight = $portfolio_total_value * $ratio_to_total_investment;
        $weighted_return = ($portfolio_value_relative_to_investment_weight/ $investment) * 100;
        $lot['total_investment'] = $total_cost_basis;
        $lot['portfolio_total_value'] = $portfolio_total_value;
        $lot['ratio_to_total_investment'] = $ratio_to_total_investment;
        $lot['portfolio_value_relative_to_investment_weight'] = $portfolio_value_relative_to_investment_weight;
        return $lot;
    }






    

    
    

}



?>