<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Main StockAnalysis Prompt
    |--------------------------------------------------------------------------
    |
    | A comprehensive prompt for performing financial analysis on a given stock ticker. This prompt guides the AI to
    | generate a structured report covering various aspects of the company, including its overview, financial
    |performance, growth outlook, ownership, market sentiment, risks, valuation, and investment strategy.
    */

    'analysis' => "Act as a top-tier financial analyst with access to real-time data and filings. Perform a comprehensive yet easy-to-understand analysis of ".env('PROMPT_TICKER_REPLACE_STRING')." using the latest 10-K/10-Q filings, analyst reports, investor presentations, institutional data, and credible online chatter. Present your findings in a structured report with clear sections and concise explanations.

⸻

    Company Overview & Strengths • What does the company do? Summarize its business model, core products/services, and revenue sources. • Identify its competitive advantages (brand, technology, patents, network effects, or market share). • Briefly profile the management team — CEO, CFO, and key leaders — highlighting their experience, leadership style, and any notable successes or controversies. • Describe its position in the competitive landscape and key rivals.

⸻

2. Financial & Stock Performance • Summarize the company\'s latest financial results (revenue, net income, cash flow, margins, and debt). • Include trend analysis over the past few years — are revenues and profits growing, stable, or declining? • Provide key financial ratios (P/E, PEG, EV/EBITDA, Debt-to-Equity, ROE, Free Cash Flow yield) and explain briefly what each indicates. • Evaluate stock performance over 1-, 5-, and 10-year periods versus major benchmarks (S&P 500 or sector index). • Note dividend policy (if any) and capital allocation approach (buybacks, debt paydown, reinvestment).

⸻

3. Growth Outlook • Outline upcoming catalysts — new products, technology developments, markets, acquisitions, or partnerships. • Discuss industry trends and macroeconomic tailwinds or headwinds that could affect growth. • Include management guidance and analyst forecasts for revenue and earnings. • Summarize the long-term strategic vision and scalability potential.

⸻

4. Ownership & Institutional Activity • List the top institutional and insider holders (Vanguard, BlackRock, State Street, executives, etc.). • Identify recent insider buying or selling patterns. • Highlight hedge-fund positions or notable activist involvement.

⸻

5. Sentiment & Market Chatter • Summarize analyst ratings (Buy/Hold/Sell breakdown and consensus price targets). • Present bullish vs. bearish arguments from analysts, media, and social channels (Reddit, X/Twitter, Seeking Alpha, financial press). • Distinguish between short-term trading sentiment and long-term investor outlook. • Include references to recent headlines or catalysts moving sentiment.

⸻

6. Risks & Weaknesses • Identify key business, financial, regulatory, and macroeconomic risks. • Explain weaknesses in the company’s model (e.g., over-reliance on one product, customer concentration, rising costs, or technology disruption). • Note any balance-sheet or governance concerns, litigation, or regulatory scrutiny.

⸻

7. Valuation Check • Compare current price vs. intrinsic value using: • DCF estimates or fair-value models (summarized). • Peer multiple comparison (EV/EBITDA, P/E, etc.). • Peter Lynch fair-value or growth-at-a-reasonable-price perspective. • Interpret whether the stock is over-, under-, or fairly-valued relative to peers and growth potential.

⸻

8. Investment Strategy & Final Take • Deliver a clear Buy / Hold / Sell recommendation, justified by the analysis above. • Summarize in plain English why this investment is attractive or risky. • Outline possible investment strategies (e.g., long-term hold, swing trade, dollar-cost averaging, or avoid). • Conclude with key takeaways and a brief, balanced bull vs. bear summary."

];
