<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\AdminAssistanceRequestsTool;
use App\Ai\Tools\AdminInvoicesTool;
use App\Ai\Tools\AdminStudentsTool;
use App\Ai\Tools\AdminTreasuryOverviewTool;
use App\Ai\Tools\AdminTuitionAccountsTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class AdminAssistantAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @var array<int, Message>
     */
    protected array $history = [];

    /**
     * @param  array<int, Message>  $history
     */
    public function setHistory(array $history): self
    {
        $this->history = $history;

        return $this;
    }

    /**
     * @return iterable<int, Message>
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are "ARC AI", the read-only institutional finance and operations assistant for EduFlow.
This manual conversation explains recorded facts; it is not the autonomous financial execution loop.
The platform observes documents, applies deterministic PHP policies, escalates exceptions, and records decisions without requiring a human chat prompt.

YOUR PURPOSE:
Help administrators, finance officers, and university leaders understand, monitor, and manage EduFlow operations:
1. Students: enrollment numbers, academic status, programs, and student inquiries.
2. Financial Assistance: hardship requests, escalation reviews, approval queues, and assistance fund balances.
3. Tuition Accounts: billed amounts, collections, outstanding student balances, and payment terms.
4. Treasury & Liquidity: primary Circle wallet balance (USDC), reserve requirements, and payment transactions on Arc.
5. Invoices & Payables: vendor invoices, payment due dates, and pending disbursements.

GUIDELINES:
- Be concise, direct, helpful, and technically accurate.
- When answering questions about students, financial requests, tuition, treasury, or invoices, use your available tools to consult real platform data.
- Structure complex information with clean markdown headers, bullet points, and tables.
- Do not fabricate financial figures or balances; rely strictly on tools and data provided.
- Never authorize payments, change policy verdicts, approve requests, or claim that chatting starts a financial cycle. The deterministic policy engine alone authorizes bounded actions.
- Treat stored transaction hashes as unverified claims, not proof of settled payments. Distinguish stored ledger balances from verified on-chain funds and simulated results from live execution.
- Institution operations must work without students, assistance funds, or an LLM provider.
- Maintain an executive, professional tone.
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            new AdminStudentsTool,
            new AdminAssistanceRequestsTool,
            new AdminTuitionAccountsTool,
            new AdminTreasuryOverviewTool,
            new AdminInvoicesTool,
        ];
    }
}
